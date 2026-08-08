<?php

namespace Database\Seeders;

use App\Models\PipelineStage;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class PipelineStageSeeder extends Seeder
{
    public function run(?Tenant $tenant = null): void
    {
        $tenants = $tenant ? [$tenant] : Tenant::all();

        $stages = [
            ['key' => 'NEW', 'label' => 'New', 'sort_order' => 1],
            ['key' => 'CONTACTED', 'label' => 'Contacted', 'sort_order' => 2],
            ['key' => 'QUALIFIED', 'label' => 'Qualified', 'sort_order' => 3],
            ['key' => 'PROPOSAL', 'label' => 'Proposal', 'sort_order' => 4],
            ['key' => 'NEGOTIATION', 'label' => 'Negotiation', 'sort_order' => 5],
            ['key' => 'WON', 'label' => 'Won', 'sort_order' => 6, 'is_won' => true],
            ['key' => 'LOST', 'label' => 'Lost', 'sort_order' => 7, 'is_lost' => true],
            ['key' => 'NURTURE', 'label' => 'Nurture', 'sort_order' => 8],
        ];

        foreach ($tenants as $t) {
            foreach ($stages as $stage) {
                PipelineStage::firstOrCreate(
                    ['tenant_id' => $t->id, 'key' => $stage['key']],
                    array_merge(['tenant_id' => $t->id], $stage)
                );
            }
        }
    }
}
