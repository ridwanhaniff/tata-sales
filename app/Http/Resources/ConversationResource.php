<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'status' => $this->status,
            'lead_id' => $this->lead_id,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer?->id,
                'name' => $this->customer?->name,
                'phone' => $this->customer?->phone,
            ]),
            'lead' => $this->whenLoaded('lead', fn () => [
                'id' => $this->lead?->id,
                'status' => $this->lead?->status,
                'temperature' => $this->lead?->temperature,
                'assigned_to' => $this->lead?->assigned_to,
            ]),
            'assigned_to' => $this->whenLoaded('assignedUser', fn () => $this->assignedUser?->id),
            'last_message' => $this->whenLoaded('lastMessage', function () {
                if (! $this->lastMessage) {
                    return null;
                }

                return [
                    'content' => $this->lastMessage->content,
                    'sender_type' => $this->lastMessage->sender_type,
                    'created_at' => $this->lastMessage->created_at?->toIso8601String(),
                ];
            }),
            'message_count' => $this->whenCounted('messages'),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
