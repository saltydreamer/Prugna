<?php

namespace App\Actions\Catalog;

use App\Models\CustomerPrice;
use App\Models\Organization;
use App\Models\Product;

/**
 * The unit price (VAT excluded) to apply to a product for a customer:
 * the agreed price, the list price minus the agreed discount, or the list price.
 */
class ResolvePrice
{
    /**
     * @return array{price: float, list_price: float, source: 'list'|'customer_price'|'customer_discount', discount_percent: float|null, customer_price_id: int|null}
     */
    public function handle(Product $product, ?Organization $organization = null): array
    {
        $listPrice = (float) $product->price;

        $agreement = $organization
            ? CustomerPrice::query()->valid()->where('organization_id', $organization->id)->where('product_id', $product->id)->first()
            : null;

        if ($agreement?->price !== null) {
            return [
                'price' => (float) $agreement->price,
                'list_price' => $listPrice,
                'source' => 'customer_price',
                'discount_percent' => null,
                'customer_price_id' => $agreement->id,
            ];
        }

        if ($agreement?->discount_percent !== null) {
            $discount = (float) $agreement->discount_percent;

            return [
                'price' => round($listPrice * (1 - $discount / 100), 4),
                'list_price' => $listPrice,
                'source' => 'customer_discount',
                'discount_percent' => $discount,
                'customer_price_id' => $agreement->id,
            ];
        }

        return [
            'price' => $listPrice,
            'list_price' => $listPrice,
            'source' => 'list',
            'discount_percent' => null,
            'customer_price_id' => null,
        ];
    }
}
