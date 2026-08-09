<?php

namespace Tests\Unit;

use App\Models\KnowledgeBaseArticle;
use App\Models\Tenant;
use App\Services\Knowledge\KnowledgeBaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KnowledgeBaseServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
    }

    private function article(array $overrides = []): KnowledgeBaseArticle
    {
        return KnowledgeBaseArticle::factory()->for($this->tenant)->create($overrides);
    }

    public function test_keyword_match_ranks_above_title_and_content_match(): void
    {
        $keyword = $this->article(['title' => 'Syarat Kredit', 'keywords' => ['garansi', 'engine']]);
        $content = $this->article(['title' => 'Syarat Kredit', 'content' => 'Kredit hanya untuk garansi 3 tahun.']);

        $result = app(KnowledgeBaseService::class)->search('garansi');

        $this->assertSame([$keyword->id, $content->id], $result->pluck('id')->all());
    }

    public function test_inactive_articles_are_never_returned(): void
    {
        $this->article(['keywords' => ['garansi'], 'status' => 'inactive']);

        $result = app(KnowledgeBaseService::class)->search('garansi');

        $this->assertCount(0, $result);
    }

    public function test_foreign_tenant_articles_are_invisible(): void
    {
        KnowledgeBaseArticle::factory()->for(Tenant::factory()->create())->create([
            'keywords' => ['garansi'],
        ]);

        $result = app(KnowledgeBaseService::class)->search('garansi');

        $this->assertCount(0, $result);
    }

    public function test_empty_query_returns_recent_active_articles(): void
    {
        $this->article(['title' => 'Artikel A']);
        $article = $this->article(['title' => 'Artikel B']);

        $result = app(KnowledgeBaseService::class)->search('');

        $this->assertTrue($result->contains('id', $article->id));
    }

    public function test_no_match_returns_empty(): void
    {
        $this->article(['keywords' => ['garansi']]);

        $result = app(KnowledgeBaseService::class)->search('tidak ada yang cocok 12345');

        $this->assertCount(0, $result);
    }
}
