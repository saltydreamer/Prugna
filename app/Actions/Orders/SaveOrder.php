<?php

namespace App\Actions\Orders;

use App\Actions\Documents\DocumentTotals;
use App\Actions\Documents\SyncDocumentLines;
use App\Enums\OrderStatus;
use App\Enums\QuoteLineType;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Organization;
use App\Models\PaymentTerm;
use App\Models\Product;
use App\Models\Quote;
use App\Models\ServiceContract;
use App\Support\CustomerSnapshot;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;

/**
 * Creates (from an accepted quote) and updates customer orders.
 */
class SaveOrder
{
    public const PREFIX = 'ORD';

    public function __construct(private SyncDocumentLines $syncLines, private SyncOrderHourPackages $hourPackages) {}

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
            ->map(fn ($line) => [
                ...$line->only(['type', 'product_id', 'code', 'description', 'unit', 'quantity', 'unit_price', 'discount_percent', 'vat_rate_id', 'package_hours', 'package_months']),
                // The packages of the accepted quote become the order's (not saved on the line).
                'quote_line_id' => $line->id,
            ])
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
        $lines = isset($data['lines']) ? $this->withPackages($data['lines']) : null;
        unset($data['lines']);

        $order = DB::transaction(function () use ($order, $data, $lines): Order {
            $order->fill($data);
            $order->customer = CustomerSnapshot::from(Organization::withTrashed()->findOrFail($order->organization_id));
            $order->payment_terms = $order->payment_term_id ? PaymentTerm::find($order->payment_term_id)?->name : null;

            if (! $order->exists) {
                $order->status = OrderStatus::Open;
                $order->forceFill(DocumentNumber::next(Order::class, self::PREFIX, $order->order_date->year));
            }

            $order->save();

            if ($lines !== null) {
                $created = $this->syncLines->handle($order->lines(), $lines, ['package_hours', 'package_months']);
                $this->adoptQuotePackages($order, $lines, $created);
            }

            $totals = DocumentTotals::summarize($order->lines()->get());
            $order->forceFill([
                'subtotal' => $totals['subtotal'],
                'vat_total' => $totals['vat_total'],
                'total' => $totals['total'],
            ])->save();

            return $order;
        });

        if ($lines !== null) {
            $this->hourPackages->handle($order);
        }

        return $order;
    }

    /**
     * An article sold as "monte ore prepagato" is a package of as many hours as its quantity.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function withPackages(array $lines): array
    {
        $packages = Product::query()->whereIn('id', array_filter(array_column($lines, 'product_id')))->where('hour_package', true)->get()->keyBy('id');

        return array_map(function (array $line) use ($packages): array {
            $isItem = ($line['type'] ?? QuoteLineType::Item->value) === QuoteLineType::Item->value || ($line['type'] ?? null) === QuoteLineType::Item;
            $product = $isItem ? $packages->get($line['product_id'] ?? null) : null;
            $hours = $product ? (float) $line['quantity'] : ($isItem && filled($line['package_hours'] ?? null) ? (float) $line['package_hours'] : null);

            return [
                ...$line,
                'package_hours' => $hours > 0 ? $hours : null,
                'package_months' => $hours > 0 ? ($line['package_months'] ?? $product?->package_months) : null,
            ];
        }, array_values($lines));
    }

    /**
     * The contracts made by the accepted quote follow the order lines copied from it.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  list<OrderLine>  $created
     */
    private function adoptQuotePackages(Order $order, array $lines, array $created): void
    {
        foreach ($lines as $index => $line) {
            if (isset($line['quote_line_id'], $created[$index])) {
                ServiceContract::query()->where('quote_line_id', $line['quote_line_id'])->whereNull('order_id')
                    ->update(['order_id' => $order->id, 'order_line_id' => $created[$index]->id]);
            }
        }
    }

    /**
     * Whether the order has priced lines.
     */
    public static function hasItems(Order $order): bool
    {
        return $order->lines()->where('type', QuoteLineType::Item->value)->exists();
    }
}
