<?php

namespace App\Http\Requests\Sales;

use App\Actions\Purchases\RebillCosts;
use App\Enums\InvoiceType;
use App\Http\Requests\Concerns\ValidatesDocumentLines;
use App\Models\Intervention;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\OrderLine;
use App\Models\PurchaseOrderLine;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class InvoiceRequest extends FormRequest
{
    use ValidatesDocumentLines;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $invoice = $this->route('invoice');

        return $invoice instanceof Invoice
            ? $this->user()->can('update', $invoice)
            : $this->user()->can('create', Invoice::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $invoice = $this->route('invoice');
        $currentOrganization = $invoice instanceof Invoice ? $invoice->organization_id : null;

        return [
            'type' => $invoice instanceof Invoice
                ? ['exclude']
                : ['required', Rule::in([InvoiceType::Invoice->value, InvoiceType::Deposit->value])],
            // An archived customer stays valid on the drafts already addressed to it (e.g. credit notes).
            'organization_id' => ['required', 'integer', Rule::exists('organizations', 'id')->where('role', 'customer')->where(
                fn ($query) => $query->whereNull('deleted_at')->when($currentOrganization, fn ($query, int $id) => $query->orWhere('id', $id)),
            )],
            'contact_id' => ['nullable', 'integer', Rule::exists('contacts', 'id')->where('organization_id', $this->input('organization_id'))],
            'title' => ['nullable', 'string', 'max:255'],
            'issue_date' => ['required', 'date', 'before_or_equal:today'],
            'customer_reference' => ['nullable', 'string', 'max:20'],
            'customer_reference_date' => ['nullable', 'date'],
            'cig' => ['nullable', 'string', 'max:15'],
            'cup' => ['nullable', 'string', 'max:15'],
            'payment_term_id' => ['nullable', 'integer', Rule::exists('payment_terms', 'id')],
            'notes' => ['nullable', 'string', 'max:10000'],
            'internal_notes' => ['nullable', 'string', 'max:10000'],
            'split_payment' => ['boolean'],
            'apply_withholding' => ['boolean'],
            'apply_pension' => ['boolean'],
            ...$this->lineRules(),
            'lines.*.order_line_id' => ['nullable', 'integer'],
            'lines.*.costs' => ['nullable', 'array', 'max:200'],
            'lines.*.costs.*' => ['string', 'regex:/^(po|ex)-\d+$/'],
            'lines.*.interventions' => ['nullable', 'array', 'max:500'],
            'lines.*.interventions.*' => ['integer'],
            'lines.*.system' => ['nullable', Rule::in([InvoiceLine::SYSTEM_DEPOSIT_DEDUCTION])],
            'lines.*.deducts_invoice_id' => ['nullable', 'integer', Rule::exists('invoices', 'id')->where('type', InvoiceType::Deposit->value)],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->requireItemLine($validator),
            fn (Validator $validator) => $this->checkOrderQuantities($validator),
            fn (Validator $validator) => $this->checkRebilledCosts($validator),
            fn (Validator $validator) => $this->checkInterventions($validator),
        ];
    }

    /**
     * Lines coming from an order can't invoice more than what is left on the order line.
     */
    private function checkOrderQuantities(Validator $validator): void
    {
        $invoice = $this->route('invoice');

        if (! $invoice instanceof Invoice || ! $invoice->order_id || $invoice->type !== InvoiceType::Invoice) {
            return;
        }

        $orderLines = OrderLine::query()->where('order_id', $invoice->order_id)->get()->keyBy('id');
        $current = $invoice->lines()->whereNotNull('order_line_id')->get()->groupBy('order_line_id')->map->sum('quantity');

        foreach ($this->input('lines', []) as $index => $line) {
            $id = $line['order_line_id'] ?? null;

            if ($id === null) {
                continue;
            }

            $orderLine = $orderLines->get($id);

            if (! $orderLine) {
                $validator->errors()->add("lines.{$index}.order_line_id", __("La riga non appartiene all'ordine della fattura."));

                continue;
            }

            $available = round($orderLine->remainingQuantity() + (float) ($current[$id] ?? 0), 3);

            if ((float) ($line['quantity'] ?? 0) > $available) {
                $validator->errors()->add("lines.{$index}.quantity", __("Sull'ordine restano da fatturare :quantity unità.", ['quantity' => rtrim(rtrim(number_format($available, 3, ',', ''), '0'), ',')]));
            }
        }
    }

    /**
     * Charged back costs must belong to this customer and not be in another invoice already.
     */
    private function checkRebilledCosts(Validator $validator): void
    {
        $lines = collect($this->input('lines', []))->filter(fn ($line) => ! empty($line['costs']) && is_array($line['costs']));

        if ($lines->isEmpty()) {
            return;
        }

        $invoice = $this->route('invoice');
        $customerId = (int) $this->input('organization_id');
        $costs = app(RebillCosts::class)->resolve($lines->flatMap(fn ($line) => $line['costs'])->unique()->values()->all());
        $seen = [];

        foreach ($lines as $index => $line) {
            foreach ($line['costs'] as $key) {
                $cost = $costs->get($key);
                $parent = $cost instanceof PurchaseOrderLine ? $cost->purchaseOrder : $cost?->expense;
                $elsewhere = $cost?->rebilledBy->contains(fn (InvoiceLine $other) => ! $invoice instanceof Invoice || $other->invoice_id !== $invoice->id);

                if (isset($seen[$key])) {
                    $validator->errors()->add("lines.{$index}.description", __('Lo stesso costo compare in più righe della fattura.'));
                } elseif (! $cost || (int) $parent->customer_id !== $customerId) {
                    $validator->errors()->add("lines.{$index}.description", __('Il costo da ribaltare non appartiene a questo cliente.'));
                } elseif ($cost instanceof PurchaseOrderLine && ! $parent->status->isCommitted()) {
                    $validator->errors()->add("lines.{$index}.description", __("L'ordine fornitore :number non è stato inviato o è annullato.", ['number' => $parent->number]));
                } elseif ($elsewhere) {
                    $validator->errors()->add("lines.{$index}.description", __('Questo costo è già stato ribaltato in un\'altra fattura.'));
                }

                $seen[$key] = true;
            }
        }
    }

    /**
     * Billed interventions must belong to this customer, be completed and billable, and not be in another invoice.
     */
    private function checkInterventions(Validator $validator): void
    {
        $lines = collect($this->input('lines', []))->filter(fn ($line) => ! empty($line['interventions']) && is_array($line['interventions']));

        if ($lines->isEmpty()) {
            return;
        }

        $invoice = $this->route('invoice');
        $customerId = (int) $this->input('organization_id');
        $interventions = Intervention::query()->with('invoiceLines')->whereIn('id', $lines->flatMap(fn ($line) => $line['interventions'])->map(fn ($id) => (int) $id)->unique()->all())->get()->keyBy('id');

        foreach ($lines as $index => $line) {
            foreach ($line['interventions'] as $id) {
                $intervention = $interventions->get((int) $id);
                $elsewhere = $intervention?->invoiceLines->contains(fn (InvoiceLine $other) => ! $invoice instanceof Invoice || $other->invoice_id !== $invoice->id);

                $message = match (true) {
                    ! $intervention || $intervention->organization_id !== $customerId => __("L'intervento non appartiene a questo cliente."),
                    $intervention->isRunning() => __("L'intervento :number è ancora in corso.", ['number' => $intervention->number]),
                    ! $intervention->billable => __("L'intervento :number non è fatturabile.", ['number' => $intervention->number]),
                    $elsewhere => __("L'intervento :number è già stato fatturato.", ['number' => $intervention->number]),
                    default => null,
                };

                if ($message) {
                    $validator->errors()->add("lines.{$index}.description", $message);

                    break;
                }
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'organization_id' => 'cliente',
            'contact_id' => 'contatto',
            'title' => 'oggetto',
            'issue_date' => 'data',
            'customer_reference' => 'riferimento ordine cliente',
            'customer_reference_date' => 'data riferimento',
            'payment_term_id' => 'condizione di pagamento',
            ...$this->lineAttributes(),
        ];
    }
}
