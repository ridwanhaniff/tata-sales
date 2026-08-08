<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    public bool $showTimeline = false;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'source' => $this->source,
            'consent_marketing' => $this->consent_marketing,
            'created_at' => $this->created_at?->toIso8601String(),
            'leads_count' => $this->when(isset($this->leads_count), $this->leads_count),
            'leads' => $this->whenLoaded('leads', fn () => $this->leads->map(fn ($lead) => [
                'id' => $lead->id,
                'status' => $lead->status,
                'temperature' => $lead->temperature,
                'score' => $lead->score,
                'estimated_value' => $lead->estimated_value !== null ? (float) $lead->estimated_value : null,
                'created_at' => $lead->created_at?->toIso8601String(),
                'product' => $lead->product ? [
                    'id' => $lead->product->id,
                    'name' => $lead->product->name,
                    'slug' => $lead->product->slug,
                ] : null,
                'campaign' => $lead->campaign ? [
                    'id' => $lead->campaign->id,
                    'name' => $lead->campaign->name,
                ] : null,
                'assigned_to' => $lead->assignedUser ? [
                    'id' => $lead->assignedUser->id,
                    'name' => $lead->assignedUser->name,
                ] : null,
            ])),
            'timeline' => $this->when($this->showTimeline, fn () => $this->timeline ?? []),
        ];
    }
}
