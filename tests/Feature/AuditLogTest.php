<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Product\ProductService;
use App\Services\Promotion\PromotionService;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
        $this->seed([PipelineStageSeeder::class]);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->for($this->tenant)->create(['role' => $role]);

        $this->actingAs($user);
        $this->withHeader('X-Tenant-ID', $this->tenant->id);
        app()->instance('currentTenant', $this->tenant);

        return $user;
    }

    public function test_promotion_create_update_delete_are_audited(): void
    {
        $manager = $this->actingAsRole('manager');

        $promotion = Promotion::factory()->for($this->tenant)->create();

        $this->putJson('/api/v1/admin/promotions/'.$promotion->id, [
            'name' => 'Promo Diubah',
            'discount_value' => 15,
        ])->assertOk();

        $this->deleteJson('/api/v1/admin/promotions/'.$promotion->id)->assertNoContent();

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'actor_id' => $manager->id,
            'action' => 'promo.updated',
            'entity_type' => 'promotion',
            'entity_id' => $promotion->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'promo.deleted',
            'entity_type' => 'promotion',
        ]);
        $this->assertDatabaseCount('audit_logs', 2);
    }

    public function test_product_price_change_is_audited(): void
    {
        $this->actingAsRole('owner');
        $product = Product::factory()->for($this->tenant)->create(['base_price' => 100000000]);

        $this->putJson('/api/v1/admin/products/'.$product->id, ['base_price' => 125000000])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'product.price_changed',
            'entity_type' => 'product',
            'entity_id' => $product->id,
        ]);

        $audit = AuditLog::query()->where('action', 'product.price_changed')->first();
        $this->assertSame(100000000.0, (float) $audit->before_data['base_price']);
        $this->assertSame(125000000.0, (float) $audit->after_data['base_price']);
    }

    public function test_product_update_without_critical_change_is_not_audited(): void
    {
        $this->actingAsRole('owner');
        $product = Product::factory()->for($this->tenant)->create();

        (new ProductService)->update($product, ['name' => 'Nama Baru Saja']);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_lead_reassign_is_audited(): void
    {
        $salesA = User::factory()->for($this->tenant)->role('sales')->create();
        $salesB = User::factory()->for($this->tenant)->role('sales')->create();
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => '6281299998888']);
        $lead = Lead::factory()->for($customer)->create(['assigned_to' => $salesA->id]);
        $this->actingAsRole('manager');

        $this->postJson('/api/v1/admin/leads/'.$lead->id.'/assign', ['user_id' => $salesB->id])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'lead.reassigned',
            'entity_id' => $lead->id,
        ]);
    }

    public function test_lead_status_change_is_audited(): void
    {
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => '6281299997777']);
        $lead = Lead::factory()->for($customer)->create(['status' => 'NEW']);
        $this->actingAsRole('manager');

        $this->putJson('/api/v1/admin/leads/'.$lead->id, ['status' => 'CONTACTED'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $this->tenant->id,
            'action' => 'lead.status_changed',
            'entity_id' => $lead->id,
        ]);

        $audit = AuditLog::query()->where('action', 'lead.status_changed')->first();
        $this->assertSame('NEW', $audit->before_data['status']);
        $this->assertSame('CONTACTED', $audit->after_data['status']);
    }

    public function test_audit_logs_are_isolated_per_tenant(): void
    {
        $tenantB = Tenant::factory()->create();
        $managerB = User::factory()->for($tenantB)->create(['role' => 'manager']);
        $promotionB = Promotion::factory()->for($tenantB)->create();

        app()->instance('currentTenant', $tenantB);
        $this->actingAs($managerB);

        (new PromotionService)->update($promotionB, ['name' => 'Ubah B']);

        app()->instance('currentTenant', $this->tenant);
        $this->actingAs($this->actingAsRole('manager'));

        $this->assertSame(0, AuditLog::query()->count());
    }
}
