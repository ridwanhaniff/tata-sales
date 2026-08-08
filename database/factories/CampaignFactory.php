<?php

namespace Database\Factories;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'utm_campaign' => fake()->slug(2),
            'status' => 'active',
        ];
    }
}
