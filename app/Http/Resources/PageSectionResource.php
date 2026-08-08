<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageSectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'block_type' => $this->block_type,
            'sort_order' => $this->sort_order,
            'config' => $this->config,
            'status' => $this->status,
        ];
    }
}
