<?php

namespace App\Http\Resources;

use App\Models\Subscription;
use App\Models\SubscriptionLine;
use App\Models\SubscriptionPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Subscription
 */
class SubscriptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status();
        $next = $this->relationLoaded('periods') ? $this->nextPeriod() : null;
        $nextDue = $next ? $this->dueDate($next) : null;

        return [
            'id' => $this->id,
            'number' => $this->number,
            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization?->only(['id', 'name'])),
            'title' => $this->title,
            'billing_period' => $this->billing_period->value,
            'billing_period_label' => $this->billing_period->label(),
            'billing_period_per' => $this->billing_period->perLabel(),
            'billing_mode' => $this->billing_mode,
            'billing_mode_label' => Subscription::modeLabel($this->billing_mode),
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'billed_until' => $this->billed_until?->toDateString(),
            'payment_term_id' => $this->payment_term_id,
            'payment_term' => $this->whenLoaded('paymentTerm', fn () => $this->paymentTerm?->name),
            'owner_id' => $this->owner_id,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner?->name),
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'amount' => $this->whenLoaded('lines', fn () => $this->amount()),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn (SubscriptionLine $line) => [
                'id' => $line->id,
                'type' => $line->type->value,
                'product_id' => $line->product_id,
                'code' => $line->code,
                'description' => $line->description,
                'unit' => $line->unit,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'discount_percent' => (float) $line->discount_percent,
                'vat_rate_id' => $line->vat_rate_id,
                'vat_rate' => (float) $line->vat_rate,
                'vat_nature' => $line->vat_nature,
                'total' => (float) $line->total,
            ])->values()),
            'next_period' => $next ? [
                'label' => Subscription::periodLabel($next),
                'starts_on' => $next['starts_on']->toDateString(),
                'ends_on' => $next['ends_on']->toDateString(),
                'due_on' => $nextDue->toDateString(),
                'is_due' => $nextDue->toDateString() <= Subscription::today()->toDateString(),
            ] : null,
            'periods' => $this->whenLoaded('periods', fn () => $this->periods->sortByDesc('starts_on')->map(fn (SubscriptionPeriod $period) => [
                'id' => $period->id,
                'label' => Subscription::periodLabel(['starts_on' => $period->starts_on, 'ends_on' => $period->ends_on]),
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
                'invoice' => $period->relationLoaded('invoice') && $period->invoice ? [
                    'id' => $period->invoice->id,
                    'label' => $period->invoice->displayNumber(),
                    'status' => $period->invoice->status->value,
                    'status_label' => $period->invoice->status->label(),
                    'status_color' => $period->invoice->status->color(),
                ] : null,
            ])->values()),
            'status' => $status,
            'status_label' => Subscription::statusLabel($status),
            'status_color' => Subscription::statusColor($status),
            'can' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'delete' => $request->user()?->can('delete', $this->resource) ?? false,
            ],
        ];
    }
}
