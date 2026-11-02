<?php

namespace App\Http\Resources;

use App\Models\CustomerPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerPrice
 */
class CustomerPriceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $listPrice = $this->relationLoaded('product') ? (float) $this->product->price : null;
        $price = $this->price !== null ? (float) $this->price : null;
        $discount = $this->discount_percent !== null ? (float) $this->discount_percent : null;

        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'product_id' => $this->product_id,
            'mode' => $price !== null ? 'price' : 'discount',
            'price' => $price,
            'discount_percent' => $discount,
            'list_price' => $listPrice,
            // Price actually applied, for display.
            'effective_price' => $price ?? ($listPrice !== null && $discount !== null ? round($listPrice * (1 - $discount / 100), 4) : null),
            'valid_until' => $this->valid_until?->toDateString(),
            'is_expired' => $this->isExpired(),
            'notes' => $this->notes,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization?->only(['id', 'name'])),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'code' => $this->product->code,
                'name' => $this->product->name,
                'unit' => $this->product->unit->value,
                'billing_period_per' => $this->product->billing_period?->perLabel(),
            ]),
        ];
    }
}
