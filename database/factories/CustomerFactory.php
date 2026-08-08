<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '+62'.fake()->numerify('812########'),
            'email' => fake()->safeEmail(),
            'consent_marketing' => true,
        ];
    }
}
