<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Calculator;
use App\Models\CalculatorInput;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\CampaignSource;
use App\Models\Customer;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadEvent;
use App\Models\LeadScore;
use App\Models\Note;
use App\Models\Notification;
use App\Models\PipelineStage;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherUsage;
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

    public function test_submit_lead_creates_customer_in_requested_tenant(): void
    {
        Customer::factory()->for($this->tenantA)->create(['phone' => '6281200000001']);

        $this->withHeader('X-Tenant-ID', $this->tenantB->id)
            ->postJson('/api/v1/leads', [
                'customer' => ['name' => 'Budi B', 'phone' => '081200000001'],
                'consent_marketing' => true,
            ])
            ->assertCreated();

        $this->assertDatabaseCount('customers', 2);
        $this->assertDatabaseCount('leads', 1);

        $lead = Lead::query()->first();
        $this->assertSame($this->tenantB->id, $lead->tenant_id);
        $this->assertSame('6281200000001', $lead->customer->phone);
        $this->assertSame($this->tenantB->id, $lead->customer->tenant_id);
    }

    public function test_tenant_b_cannot_read_notifications_of_tenant_a(): void
    {
        $userA = User::factory()->for($this->tenantA)->create(['role' => 'sales']);
        $customer = Customer::factory()->for($this->tenantA)->create();
        $lead = Lead::factory()->for($customer)->create();
        Notification::create([
            'tenant_id' => $this->tenantA->id,
            'user_id' => $userA->id,
            'channel' => 'dashboard',
            'type' => 'new_lead',
            'title' => 'Lead rahasia A',
            'data' => ['lead_id' => $lead->id],
        ]);

        app()->instance('currentTenant', $this->tenantB);
        $count = Notification::where('title', 'Lead rahasia A')->count();

        $this->assertSame(0, $count);
    }

    public function test_full_regression_all_sprint4_and_5_tables(): void
    {
        $userA = User::factory()->for($this->tenantA)->role('sales')->create();
        $customer = Customer::factory()->for($this->tenantA)->create(['phone' => '6281200000003']);
        $lead = Lead::factory()->for($customer)->create();
        $lead2 = Lead::factory()->for($customer)->create();
        $product = Product::factory()->for($this->tenantA)->create();
        $campaign = Campaign::factory()->for($this->tenantA)->create();

        LeadEvent::create(['tenant_id' => $this->tenantA->id, 'lead_id' => $lead->id, 'event_type' => 'lead_created']);
        LeadScore::create(['tenant_id' => $this->tenantA->id, 'lead_id' => $lead->id, 'event_type' => 'lead_created', 'points' => 5, 'resulting_score' => 5]);
        LeadAssignment::create(['tenant_id' => $this->tenantA->id, 'lead_id' => $lead->id, 'assigned_to' => $userA->id, 'method' => 'round_robin']);
        Note::create(['tenant_id' => $this->tenantA->id, 'lead_id' => $lead->id, 'customer_id' => $customer->id, 'user_id' => $userA->id, 'content' => 'Catatan A']);
        AuditLog::create(['tenant_id' => $this->tenantA->id, 'actor_id' => $userA->id, 'action' => 'test.aksi', 'entity_type' => 'test', 'entity_id' => $lead->id]);
        CampaignSource::create(['tenant_id' => $this->tenantA->id, 'campaign_id' => $campaign->id]);
        PipelineStage::create(['tenant_id' => $this->tenantA->id, 'key' => 'NEW', 'label' => 'New']);
        $voucher = Voucher::factory()->for($this->tenantA)->create();
        VoucherUsage::create(['tenant_id' => $this->tenantA->id, 'voucher_id' => $voucher->id, 'customer_id' => $customer->id]);

        app()->instance('currentTenant', $this->tenantB);

        $this->assertSame(0, LeadEvent::count());
        $this->assertSame(0, LeadScore::count());
        $this->assertSame(0, LeadAssignment::count());
        $this->assertSame(0, Note::count());
        $this->assertSame(0, AuditLog::count());
        $this->assertSame(0, CampaignSource::count());
        $this->assertSame(0, PipelineStage::count());
        $this->assertSame(0, Campaign::count());
        $this->assertSame(0, Notification::count());
        $this->assertSame(0, Voucher::count());
        $this->assertSame(0, VoucherUsage::count());
        $this->assertSame(0, $lead2->calculatorSessions()->count());
        $this->assertSame(0, $lead->events()->count());
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

    public function test_tenant_b_cannot_read_landing_pages_of_tenant_a(): void
    {
        LandingPage::factory()->for($this->tenantA)->create(['slug' => 'home']);

        app()->instance('currentTenant', $this->tenantB);
        $count = LandingPage::where('slug', 'home')->count();

        $this->assertSame(0, $count);
    }

    public function test_tenant_b_cannot_read_campaign_events_of_tenant_a(): void
    {
        CampaignEvent::create([
            'tenant_id' => $this->tenantA->id,
            'visitor_id' => 'visitor-rahasia',
            'event_type' => 'page_view',
            'occurred_at' => now(),
        ]);

        app()->instance('currentTenant', $this->tenantB);
        $count = CampaignEvent::where('visitor_id', 'visitor-rahasia')->count();

        $this->assertSame(0, $count);
    }

    public function test_tenant_b_cannot_read_promotions_of_tenant_a(): void
    {
        Promotion::factory()->for($this->tenantA)->create(['name' => 'Promo Rahasia A']);

        app()->instance('currentTenant', $this->tenantB);
        $count = Promotion::where('name', 'Promo Rahasia A')->count();

        $this->assertSame(0, $count);
    }

    public function test_tenant_b_cannot_read_calculators_of_tenant_a(): void
    {
        $calculator = Calculator::factory()->for($this->tenantA)->create(['name' => 'Kalkulator Rahasia A']);
        $calculator->inputs()->create([
            'tenant_id' => $this->tenantA->id,
            'key' => 'price',
            'label' => 'Harga',
            'data_type' => 'number',
        ]);

        app()->instance('currentTenant', $this->tenantB);
        $count = Calculator::where('name', 'Kalkulator Rahasia A')->count();
        $this->assertSame(0, $count);

        $count = CalculatorInput::where('key', 'price')->count();
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
