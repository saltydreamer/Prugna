<?php

namespace App\Http\Resources;

use App\Models\VatRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VatRate
 */
class VatRateResource extends JsonResource
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
            'name' => $this->name,
            'rate' => (float) $this->rate,
            'nature' => $this->nature,
            'nature_label' => $this->nature ? (VatRate::NATURES[$this->nature] ?? $this->nature) : null,
            'legal_reference' => $this->legal_reference,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'sort' => $this->sort,
            'products_count' => $this->whenCounted('products'),
        ];
    }
}
