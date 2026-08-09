<?php

namespace Database\Factories;

use App\Models\AiAgentLog;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiAgentLogFactory extends Factory
{
    protected $model = AiAgentLog::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'agent' => 'product',
            'tool_called' => 'search_products',
            'input' => ['query' => 'mobil'],
            'output' => ['found_count' => 0, 'results' => []],
            'status' => AiAgentLog::STATUS_SUCCESS,
            'latency_ms' => 120,
            'created_at' => now(),
        ];
    }
}
