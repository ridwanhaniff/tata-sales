<?php

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPagePublicTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function publishedPage(array $overrides = []): LandingPage
    {
        return LandingPage::factory()->for($this->tenant)->create([
            'status' => 'published',
            'published_at' => now(),
            ...$overrides,
        ]);
    }

    public function test_public_returns_published_page_with_ordered_active_sections(): void
    {
        $page = $this->publishedPage(['slug' => 'home']);
        $page->sections()->create(['tenant_id' => $this->tenant->id, 'block_type' => 'hero', 'sort_order' => 1, 'config' => ['heading' => 'B']]);
        $page->sections()->create(['tenant_id' => $this->tenant->id, 'block_type' => 'faq', 'sort_order' => 0, 'config' => ['heading' => 'A']]);
        $page->sections()->create(['tenant_id' => $this->tenant->id, 'block_type' => 'footer', 'sort_order' => 2, 'config' => [], 'status' => 'inactive']);

        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->getJson('/api/v1/landing-pages/home')
            ->assertOk()
            ->assertJsonCount(2, 'data.sections')
            ->assertJsonPath('data.sections.0.block_type', 'faq')
            ->assertJsonPath('data.sections.1.block_type', 'hero')
            ->assertJsonStructure(['data' => ['id', 'title', 'slug', 'seo_title', 'sections']]);
    }

    public function test_draft_page_is_not_publicly_available(): void
    {
        LandingPage::factory()->for($this->tenant)->create(['slug' => 'rahasia', 'status' => 'draft']);

        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->getJson('/api/v1/landing-pages/rahasia')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_public_landing_page_hidden_from_other_tenant(): void
    {
        $tenantB = Tenant::factory()->create();
        $this->publishedPage(['slug' => 'home']);

        $this->withHeader('X-Tenant-ID', $tenantB->id)
            ->getJson('/api/v1/landing-pages/home')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_web_render_serves_html_with_seo_meta(): void
    {
        $page = $this->publishedPage([
            'slug' => 'home',
            'seo_title' => 'TATA Dealer — Home',
            'seo_description' => 'Dealer terbaik dengan promo menarik.',
            'og_image_url' => 'https://cdn.example.com/og.webp',
        ]);
        $page->sections()->create(['tenant_id' => $this->tenant->id, 'block_type' => 'hero', 'sort_order' => 0, 'config' => ['heading' => 'Halo Dunia']]);
        $page->sections()->create(['tenant_id' => $this->tenant->id, 'block_type' => 'faq', 'sort_order' => 1, 'config' => ['heading' => 'FAQ']]);

        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get('/l/home')
            ->assertOk()
            ->assertSee('TATA Dealer — Home', false)
            ->assertSee('Halo Dunia', false)
            ->assertSee('FAQ', false)
            ->assertSee('og:image', false);
    }

    public function test_web_render_resolves_product_section_from_db(): void
    {
        $product = Product::factory()->for($this->tenant)->create([
            'name' => 'Fronx GLX',
            'slug' => 'fronx-glx',
            'status' => 'published',
            'published_at' => now(),
        ]);
        $page = $this->publishedPage(['slug' => 'home']);
        $page->sections()->create([
            'tenant_id' => $this->tenant->id,
            'block_type' => 'product',
            'sort_order' => 0,
            'config' => ['product_id' => $product->id, 'product' => ['name' => 'Stale']],
        ]);

        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->get('/l/home')
            ->assertOk()
            ->assertSee('Fronx GLX', false)
            ->assertDontSee('Stale', false);
    }
}
