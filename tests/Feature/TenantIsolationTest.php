<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenantA;

    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = Tenant::factory()->create(['slug' => 'tenant-a']);
        $this->tenantB = Tenant::factory()->create(['slug' => 'tenant-b']);

        app()->instance('currentTenant', $this->tenantA);
    }

    public function test_tenant_b_cannot_read_products_of_tenant_a(): void
    {
        Product::create(['name' => 'Fronx GLX', 'slug' => 'fronx-glx', 'base_price' => 249500000]);

        $this->assertDatabaseHas('products', ['slug' => 'fronx-glx']);

        app()->instance('currentTenant', $this->tenantB);
        $count = Product::where('slug', 'fronx-glx')->count();

        $this->assertSame(0, $count);
    }

    public function test_tenant_b_cannot_read_customers_of_tenant_a(): void
    {
        Customer::factory()->create(['phone' => '6281200000001']);

        app()->instance('currentTenant', $this->tenantB);
        $count = Customer::where('phone', '6281200000001')->count();

        $this->assertSame(0, $count);
    }

    public function test_tenant_b_cannot_read_leads_of_tenant_a(): void
    {
        $customer = Customer::factory()->create(['phone' => '6281200000002']);
        Lead::factory()->for($customer)->create(['source' => 'form']);

        app()->instance('currentTenant', $this->tenantB);
        $count = Lead::count();

        $this->assertSame(0, $count);
    }

    public function test_tenant_b_cannot_read_product_categories_of_tenant_a(): void
    {
        ProductCategory::factory()->for($this->tenantA)->create(['name' => 'SUV A']);

        app()->instance('currentTenant', $this->tenantB);
        $count = ProductCategory::where('name', 'SUV A')->count();

        $this->assertSame(0, $count);
    }

    public function test_tenant_b_cannot_read_product_variants_of_tenant_a(): void
    {
        $product = Product::factory()->for($this->tenantA)->create();
        $product->variants()->create(['tenant_id' => $this->tenantA->id, 'name' => 'Varian A', 'price' => 1000]);

        app()->instance('currentTenant', $this->tenantB);
        $count = ProductVariant::where('name', 'Varian A')->count();

        $this->assertSame(0, $count);
    }

    public function test_tenant_b_cannot_read_product_attributes_of_tenant_a(): void
    {
        $product = Product::factory()->for($this->tenantA)->create();
        $product->attributes()->create(['tenant_id' => $this->tenantA->id, 'attribute_key' => 'engine', 'attribute_value' => '1500cc']);

        app()->instance('currentTenant', $this->tenantB);
        $count = ProductAttribute::where('attribute_key', 'engine')->count();

        $this->assertSame(0, $count);
    }

    public function test_new_models_are_automatically_scoped_to_current_tenant(): void
    {
        app()->instance('currentTenant', $this->tenantB);

        $product = Product::factory()->create(['name' => 'PX-2', 'slug' => 'px-2']);

        $this->assertSame($this->tenantB->id, $product->tenant_id);
    }

    public function test_rls_blocks_cross_tenant_reads_for_app_role(): void
    {
        $pdo = new \PDO(
            'pgsql:host=127.0.0.1;port=5432;dbname='.config('database.connections.pgsql.database'),
            'tata_app',
            'tata_app_dev'
        );

        $tenantA = '11111111-1111-1111-1111-111111111111';
        $tenantB = '22222222-2222-2222-2222-222222222222';

        $pdo->beginTransaction();

        $pdo->exec("INSERT INTO tenants (id, name, slug, timezone, status, plan, settings, created_at, updated_at)
                    VALUES ('{$tenantA}', 'Tenant A', 'rls-a', 'Asia/Jakarta', 'active', 'starter', '{}', now(), now()),
                           ('{$tenantB}', 'Tenant B', 'rls-b', 'Asia/Jakarta', 'active', 'starter', '{}', now(), now())");

        // simpan produk sebagai tenant A
        $pdo->exec("SET LOCAL app.tenant_id = '{$tenantA}'");
        $pdo->exec("INSERT INTO products (id, tenant_id, name, slug, base_price, status, stock_status, featured, created_at, updated_at)
                    VALUES (gen_random_uuid(), '{$tenantA}', 'Fronx RLS', 'fronx-rls', 249500000, 'published', 'available', false, now(), now())");

        // tanpa context tenant → tidak ada baris (RLS deny by default)
        $pdo->exec('RESET app.tenant_id');
        $count = (int) $pdo->query('SELECT count(*) FROM products WHERE slug = \'fronx-rls\'')->fetchColumn();
        $this->assertSame(0, $count);

        // sebagai tenant B → data tenant A tidak terlihat
        $pdo->exec("SET LOCAL app.tenant_id = '{$tenantB}'");
        $count = (int) $pdo->query('SELECT count(*) FROM products WHERE slug = \'fronx-rls\'')->fetchColumn();
        $this->assertSame(0, $count);

        // sebagai tenant A → data terlihat
        $pdo->exec("SET LOCAL app.tenant_id = '{$tenantA}'");
        $count = (int) $pdo->query('SELECT count(*) FROM products WHERE slug = \'fronx-rls\'')->fetchColumn();
        $this->assertSame(1, $count);

        $pdo->rollBack();
    }

    public function test_super_admin_policy_allows_tenantless_user_read(): void
    {
        User::factory()->create([
            'tenant_id' => null,
            'email' => 'root@tatasales.test',
            'role' => 'super_admin',
        ]);

        $user = User::withoutGlobalScopes()->where('email', 'super@tatasales.test')->first();
        $this->assertNull($user);

        $super = User::withoutGlobalScopes()->where('email', 'root@tatasales.test')->first();
        $this->assertNotNull($super);
        $this->assertSame('super_admin', $super->role);
    }
}
