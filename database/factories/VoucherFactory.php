<?php

namespace Database\Factories;

use App\Models\Voucher;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class VoucherFactory extends Factory
{
    protected $model = Voucher::class;

    public function definition(): array
    {
        return [
            'code' => 'TATA-'.Str::upper(Str::random(4)),
            'discount_type' => 'percentage',
            'discount_value' => fake()->numberBetween(5, 25),
            'minimum_purchase' => null,
            'usage_limit' => null,
            'per_customer_limit' => 1,
            'usage_count' => 0,
            'expires_at' => now()->addDays(30),
            'status' => 'active',
            'created_at' => now(),
        ];
    }
}
