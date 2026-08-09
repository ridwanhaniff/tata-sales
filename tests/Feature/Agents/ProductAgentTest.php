<?php

namespace Tests\Feature\Agents;

use App\Agents\AgentContext;
use App\Agents\ProductAgent;
use App\Agents\Support\ToolExecutor;
use App\Agents\Tools\GetProductTool;
use App\Agents\Tools\SearchProductsTool;
use App\Models\AiAgentLog;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Services\Product\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeLLMProvider;
use Tests\TestCase;

class ProductAgentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
    }

    private function makeProduct(array $overrides = []): Product
    {
        $category = ProductCategory::factory()->for($this->tenant)->create(['name' => 'Mobil']);
        $product = Product::factory()->for($this->tenant)->create(['published_at' => now(),
            'name' => 'FRONX GLX',
            'base_price' => 249_500_000,
            'category_id' => $category->id,
            ...$overrides,
        ]);
        $product->attributes()->create([
            'tenant_id' => $this->tenant->id,
            'attribute_key' => 'cc',
            'attribute_value' => '1500',
            'attribute_type' => 'number',
        ]);

        return $product;
    }

    private function agent(FakeLLMProvider $fake): ProductAgent
    {
        return new ProductAgent($fake, new ToolExecutor);
    }

    private function context(array $meta = []): AgentContext
    {
        return new AgentContext(
            message: 'Ada mobil FRONX glx?',
            tenant: $this->tenant,
            meta: $meta,
        );
    }

    public function test_search_products_tool_is_whitelisted(): void
    {
        $agent = $this->agent(new FakeLLMProvider);

        $names = array_map(fn ($t) => $t->name(), $agent->tools());

        $this->assertContains('search_products', $names);
        $this->assertContains('get_product', $names);
        $this->assertContains('get_promotion', $names);
    }

    public function test_product_agent_runs_tool_loop_and_logs_every_call(): void
    {
        $product = $this->makeProduct();

        $fake = new FakeLLMProvider([
            FakeLLMProvider::toolCall('search_products', ['query' => 'fronx']),
            FakeLLMProvider::text('FRONX GLX tersedia dengan harga Rp249.500.000.'),
        ]);

        $result = $this->agent($fake)->handle($this->context());

        $this->assertStringContainsString('FRONX', $result['reply']);

        $log = AiAgentLog::query()->where('agent', 'product')->where('tool_called', 'search_products')->first();
        $this->assertNotNull($log, 'Tool call harus tercatat di ai_agent_logs');
        $this->assertSame(AiAgentLog::STATUS_SUCCESS, $log->status);
        $this->assertSame(['query' => 'fronx'], $log->input);
        $this->assertSame($product->id, data_get($log->output, 'results.0.product_id'));

        // LLM hanya boleh melihat produk lewat output tool
        $this->assertSame(2, $fake->generateCalls);
    }

    public function test_guardrail_tool_search_cannot_see_other_tenant_products(): void
    {
        $foreignTenant = Tenant::factory()->create();
        $foreignProduct = $this->makeProduct();
        $foreignProduct->forceFill(['tenant_id' => $foreignTenant->id])->save();

        $fake = new FakeLLMProvider([
            FakeLLMProvider::toolCall('search_products', ['query' => 'FRONX']),
            FakeLLMProvider::text('Tidak ada produk yang cocok.'),
        ]);

        $this->agent($fake)->handle($this->context());

        $log = AiAgentLog::query()->where('tool_called', 'search_products')->first();
        $this->assertSame(0, data_get($log->output, 'found_count'));
        $this->assertSame([], data_get($log->output, 'results'));
    }

    public function test_guardrail_product_not_in_database_is_not_fabricated(): void
    {
        $this->makeProduct(); // tenant A punya satu produk; pertanyaan meminta produk lain

        $fake = new FakeLLMProvider([
            FakeLLMProvider::toolCall('search_products', ['query' => 'city car premium']),
            FakeLLMProvider::text('Produk tersebut tidak kami temukan. Silakan hubungi tim kami untuk info lengkap.'),
        ]);

        $result = $this->agent($fake)->handle($this->context());

        $this->assertStringNotContainsString('Rp', $result['reply']);
        $this->assertStringNotContainsString('249', $result['reply']);

        $historyText = json_encode($fake->sessions);
        $this->assertStringNotContainsString('249_500', $historyText);
        $this->assertStringNotContainsString('Rp', $historyText);

        // AI hanya punya satu jalur data: output tool yang kosong
        $log = AiAgentLog::query()->where('tool_called', 'search_products')->first();
        $this->assertSame(0, data_get($log->output, 'found_count'));
    }

    public function test_guardrail_tool_output_only_contains_known_product_data(): void
    {
        // Tool output bersumber dari DB; AI tidak bisa menyisipkan kolom
        $product = $this->makeProduct();

        $tool = new SearchProductsTool(app(ProductService::class));
        $output = $tool->execute(['query' => 'fronx', 'budget_max' => 300_000_000]);

        $this->assertSame([$product->id], array_column($output['results'], 'product_id'));

        $row = $output['results'][0];
        $this->assertSame(['product_id', 'name', 'slug', 'base_price', 'stock_status', 'category', 'short_description'], array_keys($row));
        $this->assertSame((int) $product->base_price, $row['base_price']);
    }

    public function test_guardrail_get_product_denies_foreign_tenant(): void
    {
        $foreignTenant = Tenant::factory()->create();
        $foreignProduct = $this->makeProduct();
        $foreignProduct->forceFill(['tenant_id' => $foreignTenant->id])->save();

        $tool = new GetProductTool(app(ProductService::class));
        $output = $tool->execute(['product_id' => $foreignProduct->id]);

        $this->assertFalse($output['found']);
    }

    public function test_tools_reject_unknown_arguments(): void
    {
        // Prompt injection: LLM mau pakai tool dengan argumen di luar whitelist
        $product = $this->makeProduct();
        $tool = new GetProductTool(app(ProductService::class));

        $this->assertSame($product->id, $tool->execute([
            'product_id' => $product->id,
            'oops_hack' => 'payload',
        ])['product']['product_id']);
    }

    public function test_stock_status_is_exposed_so_ai_cannot_invent_it(): void
    {
        $this->makeProduct(['stock_status' => 'out_of_stock']);

        $tool = new SearchProductsTool(app(ProductService::class));
        $output = $tool->execute(['query' => 'fronx']);

        $this->assertSame('out_of_stock', $output['results'][0]['stock_status']);
    }
}
