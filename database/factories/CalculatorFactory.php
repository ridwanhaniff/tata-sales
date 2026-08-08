<?php

namespace Database\Factories;

use App\Models\Calculator;
use Illuminate\Database\Eloquent\Factories\Factory;

class CalculatorFactory extends Factory
{
    protected $model = Calculator::class;

    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true).' Calculator',
            'type' => 'credit',
            'status' => 'active',
        ];
    }

    /** Kalkulator kredit lengkap: price, dp, tenor, interest → cicilan & total. */
    public function credit(): static
    {
        return $this->afterCreating(function (Calculator $calculator) {
            $calculator->inputs()->createMany([
                ['tenant_id' => $calculator->tenant_id, 'key' => 'price', 'label' => 'Harga Kendaraan', 'data_type' => 'number', 'min_value' => 0, 'sort_order' => 0],
                ['tenant_id' => $calculator->tenant_id, 'key' => 'dp', 'label' => 'Uang Muka', 'data_type' => 'number', 'min_value' => 0, 'sort_order' => 1],
                ['tenant_id' => $calculator->tenant_id, 'key' => 'tenor', 'label' => 'Tenor (bulan)', 'data_type' => 'number', 'min_value' => 1, 'max_value' => 120, 'sort_order' => 2],
                ['tenant_id' => $calculator->tenant_id, 'key' => 'interest', 'label' => 'Bunga (%/tahun)', 'data_type' => 'number', 'min_value' => 0, 'max_value' => 30, 'sort_order' => 3],
            ]);

            $calculator->rules()->createMany([
                ['tenant_id' => $calculator->tenant_id, 'formula' => 'annuity(price - dp, interest, tenor)', 'rounding_policy' => 'round', 'sort_order' => 0],
                ['tenant_id' => $calculator->tenant_id, 'formula' => 'R1 * tenor', 'rounding_policy' => 'round', 'sort_order' => 1],
            ]);

            $calculator->outputs()->createMany([
                ['tenant_id' => $calculator->tenant_id, 'key' => 'monthly_installment', 'label' => 'Cicilan per Bulan', 'format' => 'currency', 'sort_order' => 0],
                ['tenant_id' => $calculator->tenant_id, 'key' => 'total_payment', 'label' => 'Total Pembayaran', 'format' => 'currency', 'sort_order' => 1],
            ]);
        });
    }
}
