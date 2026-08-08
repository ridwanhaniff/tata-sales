<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'domain' => $this->domain,
            'industry_template' => $this->industry_template,
            'timezone' => $this->timezone,
            'status' => $this->status,
            'plan' => $this->plan,
            'settings' => $this->settings,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
