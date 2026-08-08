<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'industry_template' => 'automotive-v1',
            'timezone' => 'Asia/Jakarta',
            'status' => 'active',
            'plan' => 'starter',
            'settings' => ['lead_scoring' => [], 'features' => []],
        ];
    }
}
