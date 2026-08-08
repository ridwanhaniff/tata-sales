<?php

namespace Database\Seeders;

use App\Models\Promotion;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class PromotionSeeder extends Seeder
{
    public function run(Tenant $tenant): void
    {
        app()->instance('currentTenant', $tenant);

        Promotion::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Promo Agustus '.$tenant->name],
            [
                'description' => 'Promo spesial bulan ini untuk semua unit.',
                'discount_type' => 'percentage',
                'discount_value' => 5,
                'minimum_purchase' => null,
                'usage_limit' => 100,
                'usage_count' => 0,
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDays(30),
                'status' => 'active',
            ]
        );

        Promotion::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Promo Launching '.$tenant->name],
            [
                'description' => 'Promo peluncuran — sudah berakhir.',
                'discount_type' => 'fixed_amount',
                'discount_value' => 10_000_000,
                'minimum_purchase' => null,
                'usage_limit' => 50,
                'usage_count' => 50,
                'starts_at' => now()->subDays(60),
                'ends_at' => now()->subDay(),
                'status' => 'expired',
            ]
        );
    }
}
