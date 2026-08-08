<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_login_without_tenant_context(): void
    {
        User::factory()->create([
            'tenant_id' => null,
            'email' => 'root@tatasales.test',
            'role' => 'super_admin',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'root@tatasales.test',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email', 'role']]])
            ->assertJsonPath('data.user.role', 'super_admin');
    }

    public function test_tenant_user_can_login_with_tenant_header(): void
    {
        $tenant = Tenant::factory()->create();
        User::factory()->for($tenant)->create([
            'email' => 'owner@tata.test',
            'role' => 'owner',
        ]);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson('/api/v1/auth/login', [
                'email' => 'owner@tata.test',
                'password' => 'password',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'owner');
    }

    public function test_login_with_wrong_password_returns_422(): void
    {
        $tenant = Tenant::factory()->create();
        User::factory()->for($tenant)->create(['email' => 'owner@tata.test']);

        $this->withHeader('X-Tenant-ID', $tenant->id)
            ->postJson('/api/v1/auth/login', [
                'email' => 'owner@tata.test',
                'password' => 'wrong-password',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_login_without_tenant_header_for_tenant_user_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        User::factory()->for($tenant)->create(['email' => 'owner@tata.test']);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'owner@tata.test',
            'password' => 'password',
        ])->assertStatus(422);
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_logout_revokes_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
