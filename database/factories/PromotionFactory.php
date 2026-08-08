<?php

namespace Database\Factories;

use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;

class PromotionFactory extends Factory
{
    protected $model = Promotion::class;

    public function definition(): array
    {
        return [
            'name' => 'Promo '.fake()->words(2, true),
            'description' => fake()->sentence(),
            'discount_type' => 'percentage',
            'discount_value' => fake()->numberBetween(5, 30),
            'minimum_purchase' => null,
            'usage_limit' => null,
            'usage_count' => 0,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
            'status' => 'active',
        ];
    }
}
