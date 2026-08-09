<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'trigger_event' => $this->trigger_event,
            'status' => $this->status,
            'nodes' => $this->whenLoaded('nodes', fn () => $this->nodes->map(fn ($node) => [
                'id' => $node->id,
                'node_type' => $node->node_type,
                'config' => $node->config,
                'sort_order' => $node->sort_order,
            ])),
            'runs_count' => $this->whenCounted('runs'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
