<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'utm_campaign' => $this->utm_campaign,
            'status' => $this->status,
            'budget' => $this->budget !== null ? (float) $this->budget : null,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'sources' => $this->whenLoaded('sources', fn () => $this->sources->map(fn ($source) => [
                'id' => $source->id,
                'utm_source' => $source->utm_source,
                'utm_medium' => $source->utm_medium,
                'utm_content' => $source->utm_content,
                'utm_term' => $source->utm_term,
                'referrer' => $source->referrer,
                'landing_page_id' => $source->landing_page_id,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
