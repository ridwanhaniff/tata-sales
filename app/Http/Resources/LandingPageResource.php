<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LandingPageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'template' => $this->template,
            'status' => $this->status,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'seo_keywords' => $this->seo_keywords,
            'og_title' => $this->og_title,
            'og_image_url' => $this->og_image_url,
            'canonical_url' => $this->canonical_url,
            'published_at' => $this->published_at?->toIso8601String(),
            'sections' => $this->whenLoaded('sections', fn () => PageSectionResource::collection($this->sections)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
