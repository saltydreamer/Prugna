<?php

namespace App\Actions\Purchases;

use App\Actions\Invoices\RegimeVatRate;
use App\Enums\QuoteLineType;
use App\Models\ExpenseLine;
use App\Models\Order;
use App\Models\PurchaseOrderLine;
use App\Models\VatRate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Costs to charge back to a customer ("ribaltamento costi") - supplier order lines and expense lines -
 * and the invoice lines that do it: one line per cost, or a single line per VAT rate covering several costs.
 * Costs are identified by keys "po-ID" / "ex-ID"; invoice lines carry them in "costs".
 */
class RebillCosts
{
    public function __construct(private RegimeVatRate $vatRates) {}

    /**
     * Costs of the customer (optionally of one order) not yet in an invoice, marked "rebill" or not.
     *
     * @return Collection<int, PurchaseOrderLine|ExpenseLine>
     */
    public function available(int $customerId, ?Order $order = null): Collection
    {
        return $this->query($customerId, $order, false);
    }

    /**
     * Only the costs marked "rebill" (proposed automatically when invoicing an order).
     *
     * @return Collection<int, PurchaseOrderLine|ExpenseLine>
     */
    public function pending(int $customerId, ?Order $order = null): Collection
    {
        return $this->query($customerId, $order, true);
    }

    /**
     * Costs by key, keeping the given order; unknown keys are skipped.
     *
     * @param  list<string>  $keys
     * @return Collection<string, PurchaseOrderLine|ExpenseLine>
     */
    public function resolve(array $keys): Collection
    {
        $ids = fn (string $prefix) => collect($keys)->filter(fn ($key) => str_starts_with((string) $key, $prefix))->map(fn ($key) => (int) substr((string) $key, 3))->all();

        $found = PurchaseOrderLine::query()->with(['purchaseOrder', 'rebilledBy'])->whereIn('id', $ids('po-'))->get()->toBase()
            ->concat(ExpenseLine::query()->with(['expense', 'rebilledBy'])->whereIn('id', $ids('ex-'))->get())
            ->keyBy(fn ($line) => self::key($line));

        return collect($keys)->filter(fn ($key) => $found->has($key))->mapWithKeys(fn ($key) => [$key => $found[$key]]);
    }

    /**
     * Invoice lines for the chosen costs.
     *
     * @param  Collection<int|string, PurchaseOrderLine|ExpenseLine>  $costs
     * @param  array<string, float|int|string|null>  $markups  key => markup % (missing: the markup of the cost)
     * @param  bool  $grouped  One line per VAT rate instead of one line per cost.
     * @return list<array<string, mixed>>
     */
    public function lines(Collection $costs, array $markups = [], bool $grouped = false, ?string $description = null, bool $listDetails = true): array
    {
        $amount = fn ($line) => $this->amount($line, $markups[self::key($line)] ?? null);

        if (! $grouped) {
            return $costs->map(function ($line) use ($markups) {
                $markup = isset($markups[self::key($line)]) && $markups[self::key($line)] !== '' ? (float) $markups[self::key($line)] : (float) $line->markup_percent;
                $net = (float) $line->unit_price * (1 - (float) $line->discount_percent / 100);

                return [
                    'type' => QuoteLineType::Item,
                    'product_id' => $line->product_id,
                    'code' => $line->code,
                    'description' => $line->description,
                    'unit' => $line->unit,
                    'quantity' => (float) $line->quantity,
                    'unit_price' => round($net * (1 + $markup / 100), 4),
                    'discount_percent' => 0,
                    'vat_rate_id' => $this->vatRates->for($line->vat_rate_id),
                    'costs' => [self::key($line)],
                ];
            })->values()->all();
        }

        $description = trim((string) $description) ?: __('Spese sostenute per vostro conto');
        $groups = $costs->groupBy(fn ($line) => (string) $this->vatRates->for($line->vat_rate_id));
        $rateNames = VatRate::query()->whereIn('id', $groups->keys()->filter()->all())->pluck('name', 'id');

        return $groups->map(function (Collection $lines, string $rateId) use ($amount, $description, $listDetails, $groups, $rateNames) {
            $text = $groups->count() > 1 && isset($rateNames[$rateId]) ? "{$description} – {$rateNames[$rateId]}" : $description;

            if ($listDetails) {
                $text .= "\n".$lines->map(fn ($line) => '• '.$this->detail($line))->implode("\n");
            }

            return [
                'type' => QuoteLineType::Item,
                'description' => mb_substr($text, 0, 5000),
                'unit' => null,
                'quantity' => 1,
                'unit_price' => round($lines->sum($amount), 2),
                'discount_percent' => 0,
                'vat_rate_id' => $rateId !== '' ? (int) $rateId : null,
                'costs' => $lines->map(fn ($line) => self::key($line))->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * For the cost picker.
     *
     * @return array<string, mixed>
     */
    public function option(PurchaseOrderLine|ExpenseLine $line): array
    {
        $isExpense = $line instanceof ExpenseLine;
        $document = $isExpense ? $line->expense : $line->purchaseOrder;

        return [
            'key' => self::key($line),
            'description' => $line->description,
            'quantity' => (float) $line->quantity,
            'unit' => $line->unit,
            'vat_rate_id' => $line->vat_rate_id,
            'vat_rate' => (float) $line->vat_rate,
            'vat_nature' => $line->vat_nature,
            'cost' => (float) $line->total,
            'rebill' => (bool) $line->rebill,
            'markup_percent' => (float) $line->markup_percent,
            'amount' => $line->rebillAmount(),
            'order_id' => $document->order_id,
            'source' => [
                'type' => $isExpense ? 'expense' : 'purchase_order',
                'id' => $document->id,
                'label' => $isExpense
                    ? trim($document->type->label().' '.($document->number ?? ''))
                    : __('Ordine :number', ['number' => $document->number]),
                'supplier' => $document->supplier['name'] ?? null,
                'date' => ($isExpense ? $document->document_date : $document->order_date)->toDateString(),
            ],
        ];
    }

    /**
     * Identifier of a cost across both sources ("po-12", "ex-7").
     */
    public static function key(PurchaseOrderLine|ExpenseLine $line): string
    {
        return ($line instanceof ExpenseLine ? 'ex-' : 'po-').$line->id;
    }

    /**
     * Amount charged for a cost: net cost plus markup (the given one, or the markup of the cost).
     */
    public function amount(PurchaseOrderLine|ExpenseLine $line, float|int|string|null $markup = null): float
    {
        $markup = $markup === null || $markup === '' ? (float) $line->markup_percent : (float) $markup;

        return round((float) $line->total * (1 + $markup / 100), 2);
    }

    /**
     * @return Collection<int, PurchaseOrderLine|ExpenseLine>
     */
    private function query(int $customerId, ?Order $order, bool $onlyMarked): Collection
    {
        $purchaseLines = PurchaseOrderLine::query()
            ->notRebilled()
            ->where('total', '!=', 0)
            ->when($onlyMarked, fn (Builder $query) => $query->where('rebill', true))
            ->whereHas('purchaseOrder', fn (Builder $query) => $query
                ->where('customer_id', $customerId)
                ->when($order, fn (Builder $query) => $query->where('order_id', $order->id)))
            ->with('purchaseOrder:id,number,order_date,supplier,order_id')
            ->orderBy('purchase_order_id')
            ->orderBy('position')
            ->get();

        $expenseLines = ExpenseLine::query()
            ->notRebilled()
            ->where('total', '!=', 0)
            ->when($onlyMarked, fn (Builder $query) => $query->where('rebill', true))
            ->whereHas('expense', fn (Builder $query) => $query
                ->where('customer_id', $customerId)
                ->when($order, fn (Builder $query) => $query->where('order_id', $order->id)))
            ->with('expense:id,type,number,document_date,supplier,order_id')
            ->orderBy('expense_id')
            ->orderBy('position')
            ->get();

        return $purchaseLines->toBase()->concat($expenseLines)->values();
    }

    private function detail(PurchaseOrderLine|ExpenseLine $line): string
    {
        $document = $line instanceof ExpenseLine ? $line->expense : $line->purchaseOrder;
        $date = ($line instanceof ExpenseLine ? $document->document_date : $document->order_date)->format('d/m/Y');

        return trim(strtok($line->description, "\n").' ('.implode(', ', array_filter([$document->supplier['name'] ?? null, $date])).')');
    }
}
