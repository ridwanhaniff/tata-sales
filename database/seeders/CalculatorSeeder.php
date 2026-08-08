<?php

namespace Database\Seeders;

use App\Models\Calculator;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class CalculatorSeeder extends Seeder
{
    public function run(Tenant $tenant): void
    {
        app()->instance('currentTenant', $tenant);

        $calculator = Calculator::firstOrCreate(
            ['tenant_id' => $tenant->id, 'type' => 'credit', 'name' => 'Simulasi Kredit '.$tenant->name],
            ['status' => 'active']
        );

        $calculator->inputs()->delete();
        $calculator->rules()->delete();
        $calculator->outputs()->delete();

        $calculator->inputs()->createMany([
            ['tenant_id' => $tenant->id, 'key' => 'price', 'label' => 'Harga Kendaraan', 'data_type' => 'number', 'min_value' => 0, 'is_required' => true, 'sort_order' => 0],
            ['tenant_id' => $tenant->id, 'key' => 'dp', 'label' => 'Uang Muka (DP)', 'data_type' => 'number', 'min_value' => 0, 'is_required' => true, 'sort_order' => 1],
            ['tenant_id' => $tenant->id, 'key' => 'tenor', 'label' => 'Tenor (bulan)', 'data_type' => 'select', 'options' => [
                ['value' => 12, 'label' => '12 bulan'],
                ['value' => 24, 'label' => '24 bulan'],
                ['value' => 36, 'label' => '36 bulan'],
                ['value' => 48, 'label' => '48 bulan'],
                ['value' => 60, 'label' => '60 bulan'],
            ], 'is_required' => true, 'sort_order' => 2],
            ['tenant_id' => $tenant->id, 'key' => 'interest', 'label' => 'Bunga (%/tahun)', 'data_type' => 'number', 'min_value' => 0, 'max_value' => 30, 'is_required' => true, 'sort_order' => 3],
        ]);

        $calculator->rules()->createMany([
            ['tenant_id' => $tenant->id, 'formula' => 'annuity(price - dp, interest, tenor)', 'rounding_policy' => 'round', 'sort_order' => 0],
            ['tenant_id' => $tenant->id, 'formula' => 'R1 * tenor', 'rounding_policy' => 'round', 'sort_order' => 1],
        ]);

        $calculator->outputs()->createMany([
            ['tenant_id' => $tenant->id, 'key' => 'monthly_installment', 'label' => 'Cicilan per Bulan', 'format' => 'currency', 'sort_order' => 0],
            ['tenant_id' => $tenant->id, 'key' => 'total_payment', 'label' => 'Total Pembayaran', 'format' => 'currency', 'sort_order' => 1],
        ]);
    }
}
