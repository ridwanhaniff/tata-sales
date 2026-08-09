<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FollowUpStepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'trigger_event' => $this->trigger_event,
            'delay_minutes' => $this->delay_minutes,
            'message' => $this->message,
            'condition' => $this->condition,
            'action' => $this->action,
            'sort_order' => $this->sort_order,
            'status' => $this->status,
        ];
    }
}
