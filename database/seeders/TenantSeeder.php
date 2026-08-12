<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::factory()->create([
            'name' => 'TATA Demo Auto',
            'slug' => 'demo-auto',
            'domain' => '127.0.0.1',
            'industry_template' => 'automotive-v1',
        ]);

        User::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Owner Demo',
            'email' => 'owner@demo.tatasales.test',
            'role' => 'owner',
            'password_hash' => Hash::make('password'),
        ]);

        User::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Andi Sales',
            'email' => 'sales@demo.tatasales.test',
            'role' => 'sales',
            'password_hash' => Hash::make('password'),
        ]);

        $this->command?->getOutput()?->writeln("Tenant demo created: {$tenant->slug}");

        Tenant::factory()->create(['name' => 'Tenant B (isolated)', 'slug' => 'tenant-b']);
    }
}
