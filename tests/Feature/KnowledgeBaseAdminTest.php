<?php

namespace Tests\Feature;

use App\Models\KnowledgeBaseArticle;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeBaseAdminTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->for($this->tenant)->create(['role' => $role]);

        $this->actingAs($user);
        $this->withHeader('X-Tenant-ID', $this->tenant->id);

        return $user;
    }

    public function test_manager_can_create_update_and_delete_article(): void
    {
        $this->actingAsRole('manager');

        $response = $this->postJson('/api/v1/admin/knowledge', [
            'category' => 'faq',
            'title' => 'Cara booking',
            'content' => 'Booking lewat formulir di situs.',
            'keywords' => ['booking', 'cara'],
        ])->assertCreated();
        $articleId = $response->json('data.id');

        $this->putJson('/api/v1/admin/knowledge/'.$articleId, [
            'content' => 'Booking lewat formulir atau WhatsApp.',
        ])->assertOk()
            ->assertJsonPath('data.content', 'Booking lewat formulir atau WhatsApp.');

        $this->deleteJson('/api/v1/admin/knowledge/'.$articleId)
            ->assertNoContent();

        $this->assertDatabaseMissing('knowledge_base_articles', ['id' => $articleId]);
    }

    public function test_search_list_filters_and_paginates(): void
    {
        $this->actingAsRole('owner');
        KnowledgeBaseArticle::factory()->for($this->tenant)->create([
            'title' => 'Garansi Mesin',
            'category' => 'policy',
            'keywords' => ['garansi'],
        ]);
        KnowledgeBaseArticle::factory()->for($this->tenant)->create([
            'title' => 'Skrip Opening',
            'category' => 'script',
            'keywords' => ['opening'],
        ]);

        $this->getJson('/api/v1/admin/knowledge?search=garansi')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Garansi Mesin');

        $this->getJson('/api/v1/admin/knowledge?category=script')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Skrip Opening');
    }

    public function test_sales_cannot_touch_knowledge_base(): void
    {
        $this->actingAsRole('sales');

        $this->postJson('/api/v1/admin/knowledge', [
            'category' => 'faq',
            'title' => 'X',
            'content' => 'Y',
        ])->assertForbidden();
    }
}
