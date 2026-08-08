<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantCrudTest extends TestCase
{
    use RefreshDatabase;

    private function superAdminToken(): string
    {
        $user = User::factory()->create(['tenant_id' => null, 'role' => 'super_admin']);

        return $user->createToken('auth')->plainTextToken;
    }

    private function ownerToken(): string
    {
        $user = User::factory()->create(['role' => 'owner']);

        return $user->createToken('auth')->plainTextToken;
    }

    public function test_super_admin_can_list_tenants(): void
    {
        Tenant::factory()->count(3)->create();

        $this->withToken($this->superAdminToken())
            ->getJson('/api/v1/admin/tenants')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'status']], 'meta' => ['total']]);
    }

    public function test_super_admin_can_create_tenant(): void
    {
        $this->withToken($this->superAdminToken())
            ->postJson('/api/v1/admin/tenants', [
                'name' => 'Dealer Baru',
                'slug' => 'dealer-baru',
                'industry_template' => 'automotive-v1',
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'dealer-baru');

        $this->assertDatabaseHas('tenants', ['slug' => 'dealer-baru']);
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        Tenant::factory()->create(['slug' => 'dealer-baru']);

        $this->withToken($this->superAdminToken())
            ->postJson('/api/v1/admin/tenants', ['name' => 'Dup', 'slug' => 'dealer-baru'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_super_admin_can_update_and_delete_tenant(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'old-slug']);

        $this->withToken($this->superAdminToken())
            ->putJson("/api/v1/admin/tenants/{$tenant->id}", ['name' => 'Updated', 'status' => 'suspended'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated');

        $this->withToken($this->superAdminToken())
            ->deleteJson("/api/v1/admin/tenants/{$tenant->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
    }

    public function test_non_super_admin_cannot_access_tenants(): void
    {
        $this->withToken($this->ownerToken())
            ->getJson('/api/v1/admin/tenants')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_guest_cannot_access_tenants(): void
    {
        $this->getJson('/api/v1/admin/tenants')
            ->assertStatus(401);
    }
}
