<?php

namespace App\Actions\Orders;

use App\Actions\Documents\DocumentTotals;
use App\Actions\Documents\SyncDocumentLines;
use App\Enums\OrderStatus;
use App\Enums\QuoteLineType;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PaymentTerm;
use App\Models\Quote;
use App\Support\CustomerSnapshot;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;

/**
 * Creates (from an accepted quote) and updates customer orders.
 */
class SaveOrder
{
    public const PREFIX = 'ORD';

    public function __construct(private SyncDocumentLines $syncLines) {}

    /**
     * The order of an accepted quote: lines included in the quote (selected optional lines too).
     * A quote has at most one order.
     */
    public function fromQuote(Quote $quote, int $ownerId): Order
    {
        if ($existing = Order::query()->where('quote_id', $quote->id)->first()) {
            return $existing;
        }

        $quote->loadMissing('lines');

        $lines = $quote->lines
            ->filter(fn ($line) => ! $line->isItem() || $line->isIncluded())
            ->map(fn ($line) => $line->only(['type', 'product_id', 'code', 'description', 'unit', 'quantity', 'unit_price', 'discount_percent', 'vat_rate_id']))
            ->values()
            ->all();

        return $this->handle(new Order, [
            'quote_id' => $quote->id,
            'organization_id' => $quote->organization_id,
            'contact_id' => $quote->contact_id,
            'owner_id' => $quote->owner_id ?? $ownerId,
            'title' => $quote->title,
            'order_date' => today(),
            'payment_term_id' => $quote->payment_term_id,
            'lines' => $lines,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data  Header fields plus "lines".
     */
    public function handle(Order $order, array $data): Order
    {
        $lines = $data['lines'] ?? null;
        unset($data['lines']);

        return DB::transaction(function () use ($order, $data, $lines): Order {
            $order->fill($data);
            $order->customer = CustomerSnapshot::from(Organization::withTrashed()->findOrFail($order->organization_id));
            $order->payment_terms = $order->payment_term_id ? PaymentTerm::find($order->payment_term_id)?->name : null;

            if (! $order->exists) {
                $order->status = OrderStatus::Open;
                $order->forceFill(DocumentNumber::next(Order::class, self::PREFIX, $order->order_date->year));
            }

            $order->save();

            if ($lines !== null) {
                $this->syncLines->handle($order->lines(), $lines);
            }

            $totals = DocumentTotals::summarize($order->lines()->get());
            $order->forceFill([
                'subtotal' => $totals['subtotal'],
                'vat_total' => $totals['vat_total'],
                'total' => $totals['total'],
            ])->save();

            return $order;
        });
    }

    /**
     * Whether the order has priced lines.
     */
    public static function hasItems(Order $order): bool
    {
        return $order->lines()->where('type', QuoteLineType::Item->value)->exists();
    }
}
