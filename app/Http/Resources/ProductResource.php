<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->category_id,
            'business_line_id' => $this->business_line_id,
            'category' => $this->whenLoaded('category', fn () => $this->category?->only(['id', 'name', 'color'])),
            'unit' => $this->unit->value,
            'price' => (float) $this->price,
            'cost' => $this->cost !== null ? (float) $this->cost : null,
            'margin' => $this->margin(),
            'vat_rate_id' => $this->vat_rate_id,
            'vat_rate' => $this->whenLoaded('vatRate', fn () => VatRateResource::make($this->vatRate)->resolve($request)),
            'billing_period' => $this->billing_period?->value,
            'billing_period_label' => $this->billing_period?->label(),
            'billing_period_per' => $this->billing_period?->perLabel(),
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'customer_prices_count' => $this->whenCounted('customerPrices'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'can' => $this->when($request->user() !== null, fn () => [
                'update' => $request->user()->can('update', $this->resource),
                'delete' => $request->user()->can('delete', $this->resource),
            ]),
        ];
    }
}
