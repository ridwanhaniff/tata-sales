<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(TenantSeeder::class);

        foreach (Tenant::all() as $tenant) {
            app()->instance('currentTenant', $tenant);
            $this->callWith(PipelineStageSeeder::class, ['tenant' => $tenant]);
        }
    }
}
