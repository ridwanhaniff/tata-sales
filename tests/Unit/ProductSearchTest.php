<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Services\Product\ProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSearchTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
    }

    public function test_search_returns_only_published_products(): void
    {
        $category = ProductCategory::factory()->for($this->tenant)->create(['name' => 'Mobil']);

        $published = Product::factory()->for($this->tenant)->create(['published_at' => now(),
            'name' => 'FRONX GLX',
            'base_price' => 200_000_000,
            'category_id' => $category->id,
        ]);
        $draft = Product::factory()->for($this->tenant)->create(['published_at' => now(),
            'name' => 'FRONX Draft',
            'status' => 'draft',
            'category_id' => $category->id,
        ]);

        $service = app(ProductService::class);
        $results = $service->search('fronx');

        $this->assertNotEmpty($results);
        $this->assertTrue($results->pluck('id')->contains($published->id));
        $this->assertFalse($results->pluck('id')->contains($draft->id));
    }

    public function test_search_filters_by_category_and_budget(): void
    {
        $category = ProductCategory::factory()->for($this->tenant)->create(['name' => 'Mobil']);
        $otherCategory = ProductCategory::factory()->for($this->tenant)->create(['name' => 'Motor']);

        $murah = Product::factory()->for($this->tenant)->create(['published_at' => now(),
            'name' => 'City Car A',
            'base_price' => 120_000_000,
            'category_id' => $category->id,
        ]);
        $mahal = Product::factory()->for($this->tenant)->create(['published_at' => now(),
            'name' => 'City Car B',
            'base_price' => 450_000_000,
            'category_id' => $category->id,
        ]);
        Product::factory()->for($this->tenant)->create(['published_at' => now(),
            'name' => 'Scooter',
            'base_price' => 25_000_000,
            'category_id' => $otherCategory->id,
        ]);

        $service = app(ProductService::class);

        $results = $service->search('city', category: 'Mobil');
        $this->assertCount(2, $results);

        $results = $service->search('city', category: 'Mobil', budgetMax: 300_000_000);
        $this->assertCount(1, $results);
        $this->assertSame($murah->id, $results->first()->id);
        $this->assertNotContains($mahal->id, $results->pluck('id')->all());
    }

    public function test_search_limits_results(): void
    {
        foreach (range(1, 5) as $i) {
            Product::factory()->for($this->tenant)->create(['published_at' => now(), 'name' => "Produk {$i}"]);
        }

        $results = app(ProductService::class)->search('produk', limit: 2);

        $this->assertCount(2, $results);
    }

    public function test_find_only_returns_published_scoped_to_tenant(): void
    {
        $product = Product::factory()->for($this->tenant)->create(['published_at' => now()]);
        $draft = Product::factory()->for($this->tenant)->create(['published_at' => null, 'status' => 'draft']);

        $service = app(ProductService::class);

        $this->assertNotNull($service->find($product->id));
        $this->assertNull($service->find($draft->id));
        $this->assertNull($service->find('00000000-0000-0000-0000-000000000000'));

        $foreignTenant = Tenant::factory()->create();
        $foreignProduct = Product::factory()->for($foreignTenant)->create();

        $this->assertNull($service->find($foreignProduct->id), 'Produk tenant lain tidak boleh terlihat.');
    }

    public function test_search_empty_query_returns_latest_published(): void
    {
        $category = ProductCategory::factory()->for($this->tenant)->create();
        Product::factory()->for($this->tenant)->create(['published_at' => now(), 'name' => 'Beta', 'base_price' => 200_000_000, 'category_id' => $category->id]);
        Product::factory()->for($this->tenant)->create(['published_at' => now(), 'name' => 'Alfa', 'base_price' => 100_000_000, 'category_id' => $category->id]);

        $results = app(ProductService::class)->search('');

        $this->assertCount(2, $results);
        $this->assertSame('Alfa', $results->first()->name);
    }
}
