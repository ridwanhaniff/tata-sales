<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SalesTargetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'period' => $this->period,
            'user_id' => $this->user_id,
            'sales_team_id' => $this->sales_team_id,
            'target_leads' => $this->target_leads,
            'target_revenue' => $this->target_revenue,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'team' => $this->whenLoaded('team', fn () => [
                'id' => $this->team->id,
                'name' => $this->team->name,
            ]),
        ];
    }
}
