<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
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
            'type' => $this->type,
            'title' => $this->data['title'] ?? '',
            'message' => $this->data['message'] ?? '',
            'icon' => $this->data['icon'] ?? 'mdi-bell-outline',
            'color' => $this->data['color'] ?? 'primary',
            'action_url' => $this->data['action_url'] ?? null,
            'action_label' => $this->data['action_label'] ?? null,
            'sender' => $this->data['sender'] ?? null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
