<?php

namespace Tests\Feature\Agents;

use App\Agents\AgentContext;
use App\Agents\RecommendationAgent;
use App\Agents\Support\ToolExecutor;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLLMProvider;
use Tests\TestCase;

class RecommendationAgentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
    }

    public function test_tools_whitelist_is_search_products_and_get_promotion(): void
    {
        $agent = new RecommendationAgent(new FakeLLMProvider, new ToolExecutor);

        $this->assertSame('recommendation', $agent->name());
        $this->assertSame(['search_products', 'get_promotion'], array_map(fn ($t) => $t->name(), $agent->tools()));
    }

    public function test_agent_composes_reply_from_search_and_promotion_tools(): void
    {
        $category = ProductCategory::factory()->for($this->tenant)->create(['name' => 'Mobil']);
        Product::factory()->for($this->tenant)->create([
            'name' => 'FRONX GLX',
            'base_price' => 249_500_000,
            'published_at' => now(),
            'category_id' => $category->id,
        ]);

        $fake = new FakeLLMProvider([
            FakeLLMProvider::toolCall('search_products', ['query' => 'FRONX', 'budget_max' => 250_000_000]),
            FakeLLMProvider::toolCall('get_promotion', []),
            FakeLLMProvider::text('Rekomendasi saya: FRONX GLX seharga Rp249.500.000.'),
        ]);

        $agent = new RecommendationAgent($fake, new ToolExecutor);

        $result = $agent->handle(new AgentContext(
            message: 'Rekomendasikan mobil untuk saya.',
            tenant: $this->tenant,
            meta: ['lead' => [
                'status' => 'QUALIFIED',
                'estimated_value' => 250_000_000,
                'product_id' => null,
            ]],
        ));

        $this->assertSame('Rekomendasi saya: FRONX GLX seharga Rp249.500.000.', $result['reply']);
        $this->assertSame(3, $fake->generateCalls);
    }

    public function test_agent_without_tools_still_replies_from_final(): void
    {
        $fake = new FakeLLMProvider([
            FakeLLMProvider::text('Belum ada produk yang cocok dengan budget Anda.'),
        ]);

        $agent = new RecommendationAgent($fake, new ToolExecutor);

        $result = $agent->handle(new AgentContext(
            message: 'Rekomendasikan mobil untuk saya.',
            tenant: $this->tenant,
        ));

        $this->assertSame('Belum ada produk yang cocok dengan budget Anda.', $result['reply']);
        $this->assertSame(1, $fake->generateCalls);
    }
}
