<?php

namespace App\Http\Controllers\Sales;

use App\Actions\Interventions\BillInterventions;
use App\Actions\Invoices\BuildInvoice;
use App\Actions\Invoices\SaveInvoice;
use App\Http\Controllers\Controller;
use App\Models\Intervention;
use App\Models\Invoice;
use App\Models\Order;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * "Fattura interventi": choose the interventions of a customer still to invoice and bill them,
 * in a new draft invoice or as lines added to the invoice being edited.
 */
class InterventionInvoiceController extends Controller
{
    public function __construct(private BillInterventions $bill) {}

    /**
     * Interventions of the customer (optionally of an order) still to invoice.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('create', Invoice::class);

        $data = $request->validate([
            'organization_id' => ['required', 'integer'],
            'order_id' => ['nullable', 'integer'],
            'exclude' => ['nullable', 'array'],
            'exclude.*' => ['integer'],
        ]);

        $order = isset($data['order_id']) ? Order::query()->find($data['order_id']) : null;
        $exclude = array_map('intval', $data['exclude'] ?? []);

        return response()->json([
            'data' => $this->bill->available((int) $data['organization_id'], $order)
                ->reject(fn (Intervention $intervention) => in_array($intervention->id, $exclude, true))
                ->map(fn (Intervention $intervention) => $this->bill->option($intervention))
                ->values(),
        ]);
    }

    /**
     * Invoice lines for the chosen interventions, added to the invoice editor.
     */
    public function lines(Request $request): JsonResponse
    {
        $this->authorize('create', Invoice::class);

        $data = $this->validateChoice($request);

        return response()->json([
            'data' => collect($this->bill->lines($this->chosen($data), $request->boolean('grouped'), $request->boolean('list_details', true)))
                ->map(fn (array $line) => [...$line, 'type' => $line['type']->value])
                ->values(),
        ]);
    }

    /**
     * New draft invoice for the chosen interventions.
     */
    public function store(Request $request, BuildInvoice $build, SaveInvoice $saveInvoice, Settings $settings): RedirectResponse
    {
        $this->authorize('create', Invoice::class);

        $data = $this->validateChoice($request);
        $interventions = $this->chosen($data);
        $order = isset($data['order_id']) ? Order::query()->find($data['order_id']) : null;

        $invoice = DB::transaction(fn () => $saveInvoice->handle(new Invoice, [
            ...$build->fromInterventions((int) $data['organization_id'], $order, $interventions, $request->boolean('grouped'), $request->boolean('list_details', true)),
            'owner_id' => $request->user()->id,
            'notes' => $settings->get('fiscal.invoice_notes'),
        ]));

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{1} Bozza di fattura pronta per 1 intervento: controllala ed emettila.|[2,*] Bozza di fattura pronta per :count interventi: controllala ed emettila.', $interventions->count())]);

        return to_route('sales.invoices.show', $invoice);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateChoice(Request $request): array
    {
        return $request->validate([
            'organization_id' => ['required', 'integer', Rule::exists('organizations', 'id')->where('role', 'customer')],
            'order_id' => ['nullable', 'integer', Rule::exists('orders', 'id')->where('organization_id', $request->input('organization_id'))],
            'interventions' => ['required', 'array', 'min:1', 'max:500'],
            'interventions.*' => ['integer'],
            'grouped' => ['boolean'],
            'list_details' => ['boolean'],
        ], [], ['interventions' => 'interventi']);
    }

    /**
     * The chosen interventions that are still to invoice for the customer.
     *
     * @param  array<string, mixed>  $data
     * @return Collection<int, Intervention>
     */
    private function chosen(array $data): Collection
    {
        $ids = array_map('intval', $data['interventions']);
        $interventions = $this->bill->available((int) $data['organization_id'])->filter(fn (Intervention $intervention) => in_array($intervention->id, $ids, true))->values();

        if ($interventions->isEmpty()) {
            throw ValidationException::withMessages(['interventions' => __('Gli interventi scelti non sono più da fatturare: forse sono già in un\'altra fattura.')]);
        }

        return $interventions;
    }
}
