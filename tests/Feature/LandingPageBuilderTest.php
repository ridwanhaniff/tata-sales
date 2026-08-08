<?php

namespace Tests\Feature;

use App\Models\LandingPage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageBuilderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->for($this->tenant)->create(['role' => $role]);

        $this->actingAs($user);
        $this->withHeader('X-Tenant-ID', $this->tenant->id);
        app()->instance('currentTenant', $this->tenant);

        return $user;
    }

    public function test_owner_can_create_landing_page_with_seo_fields(): void
    {
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/admin/landing-pages', [
            'title' => 'Promo Agustus',
            'slug' => 'promo-agustus',
            'seo_title' => 'Promo Agustus — Dealer Terpercaya',
            'seo_description' => 'Promo kendaraan terbaik bulan Agustus.',
            'og_image_url' => 'https://cdn.example.com/promo.webp',
        ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'promo-agustus')
            ->assertJsonPath('data.seo_title', 'Promo Agustus — Dealer Terpercaya');
    }

    public function test_owner_can_add_sections_to_landing_page(): void
    {
        $page = LandingPage::factory()->for($this->tenant)->create();
        $this->actingAsRole('owner');

        $this->postJson("/api/v1/admin/landing-pages/{$page->id}/sections", [
            'block_type' => 'hero',
            'config' => ['heading' => 'Halo Dunia', 'subheading' => 'Sub'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.block_type', 'hero')
            ->assertJsonPath('data.config.heading', 'Halo Dunia');
    }

    public function test_owner_can_reorder_and_update_sections(): void
    {
        $page = LandingPage::factory()->for($this->tenant)->create();
        [$hero, $faq] = [
            $page->sections()->create(['tenant_id' => $this->tenant->id, 'block_type' => 'hero', 'sort_order' => 0, 'config' => ['heading' => 'Hero']]),
            $page->sections()->create(['tenant_id' => $this->tenant->id, 'block_type' => 'faq', 'sort_order' => 1, 'config' => ['heading' => 'FAQ']]),
        ];

        $this->actingAsRole('owner');

        $this->putJson("/api/v1/admin/landing-pages/{$page->id}/sections/{$faq->id}", [
            'config' => ['heading' => 'FAQ Baru'],
        ])->assertOk()
            ->assertJsonPath('data.config.heading', 'FAQ Baru');

        $this->putJson("/api/v1/admin/landing-pages/{$page->id}/sections/reorder", [
            'sections' => [
                ['id' => $faq->id],
                ['id' => $hero->id],
            ],
        ])->assertOk();

        $this->assertSame(0, $faq->fresh()->sort_order);
        $this->assertSame(1, $hero->fresh()->sort_order);
    }

    public function test_only_owner_manager_content_manager_can_build(): void
    {
        $this->actingAsRole('sales');

        $this->postJson('/api/v1/admin/landing-pages', ['title' => 'X', 'slug' => 'x'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_publish_and_unpublish_landing_page(): void
    {
        $page = LandingPage::factory()->for($this->tenant)->create(['status' => 'draft']);
        $this->actingAsRole('owner');

        $this->postJson("/api/v1/admin/landing-pages/{$page->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->postJson("/api/v1/admin/landing-pages/{$page->id}/unpublish")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_delete_landing_page_cascades_sections(): void
    {
        $page = LandingPage::factory()->for($this->tenant)->create();
        $page->sections()->create(['tenant_id' => $this->tenant->id, 'block_type' => 'hero', 'config' => []]);

        $this->actingAsRole('owner');
        $this->deleteJson("/api/v1/admin/landing-pages/{$page->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('page_sections', ['landing_page_id' => $page->id]);
    }
}
