<?php

namespace App\Http\Resources;

use App\Models\ServiceContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceContract
 */
class ServiceContractResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status();
        $used = $this->usedMinutes();

        return [
            'id' => $this->id,
            'number' => $this->number,
            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization?->only(['id', 'name'])),
            'title' => $this->title,
            'hours' => (float) $this->hours,
            'total_minutes' => $this->totalMinutes(),
            'used_minutes' => $used,
            'remaining_minutes' => max(0, $this->totalMinutes() - $used),
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'mode' => $this->mode?->value,
            'mode_label' => $this->mode?->label() ?? __('Remoto e on site'),
            'category_ids' => array_map('intval', $this->category_ids ?? []),
            'price' => (float) $this->price,
            'product_id' => $this->product_id,
            'vat_rate_id' => $this->vat_rate_id,
            'invoice' => $this->whenLoaded('invoice', fn () => $this->invoice ? ['id' => $this->invoice->id, 'label' => $this->invoice->displayNumber(), 'status' => $this->invoice->status->value] : null),
            'quote' => $this->whenLoaded('quote', fn () => $this->quote ? ['id' => $this->quote->id, 'number' => $this->quote->number] : null),
            'order' => $this->whenLoaded('order', fn () => $this->order ? ['id' => $this->order->id, 'number' => $this->order->number] : null),
            'manager_id' => $this->manager_id,
            'manager' => $this->whenLoaded('manager', fn () => $this->manager?->name),
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'status' => $status,
            'status_label' => ServiceContract::statusLabel($status),
            'status_color' => ServiceContract::statusColor($status),
            'can' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
            ],
        ];
    }
}
