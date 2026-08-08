<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'source' => 'form',
            'status' => 'NEW',
            'temperature' => 'COLD',
            'score' => 0,
        ];
    }
}
