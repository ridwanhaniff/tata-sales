<?php

namespace Tests\Feature;

use App\Models\Promotion;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voucher;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherAdminTest extends TestCase
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

        return $user;
    }

    private function makePromotion(): Promotion
    {
        return Promotion::factory()->for($this->tenant)->create([
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'status' => 'active',
        ]);
    }

    public function test_owner_can_generate_unique_voucher_codes_with_prefix(): void
    {
        $promotion = $this->makePromotion();
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/admin/promotions/'.$promotion->id.'/vouchers/generate', [
            'count' => 25,
            'prefix' => 'TATA',
        ])
            ->assertCreated()
            ->assertJsonCount(25, 'meta.vouchers');

        $codes = Voucher::query()->where('promotion_id', $promotion->id)->pluck('code');
        $this->assertCount(25, $codes);
        $this->assertSame(25, $codes->unique()->count());

        foreach ($codes as $code) {
            $this->assertStringStartsWith('TATA-', $code);
            $this->assertSame(9, strlen($code));
        }

        $this->assertDatabaseCount('vouchers', 25);
    }

    public function test_generate_without_prefix_defaults_to_tata(): void
    {
        $promotion = $this->makePromotion();
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/admin/promotions/'.$promotion->id.'/vouchers/generate', ['count' => 3])
            ->assertCreated();

        foreach (Voucher::query()->pluck('code') as $code) {
            $this->assertStringStartsWith('TATA-', $code);
        }
    }

    public function test_generate_rejects_invalid_count(): void
    {
        $promotion = $this->makePromotion();
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/admin/promotions/'.$promotion->id.'/vouchers/generate', ['count' => 0])
            ->assertStatus(422);

        $this->postJson('/api/v1/admin/promotions/'.$promotion->id.'/vouchers/generate', ['count' => 500])
            ->assertStatus(422);
    }

    public function test_generate_rejects_invalid_prefix(): void
    {
        $promotion = $this->makePromotion();
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/admin/promotions/'.$promotion->id.'/vouchers/generate', [
            'count' => 2,
            'prefix' => 'SPASI SPASI',
        ])->assertStatus(422);
    }

    public function test_voucher_inherits_promotion_discount_and_expiry(): void
    {
        $promotion = $this->makePromotion();
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/admin/promotions/'.$promotion->id.'/vouchers/generate', [
            'count' => 1,
            'prefix' => 'TATA',
        ])->assertCreated();

        $voucher = Voucher::query()->where('promotion_id', $promotion->id)->first();

        $this->assertSame('percentage', $voucher->discount_type);
        $this->assertSame('10.00', (string) $voucher->discount_value);
        $this->assertSame('active', $voucher->status);
        $this->assertSame($promotion->ends_at->toDateTimeString(), $voucher->expires_at->toDateTimeString());
    }

    public function test_vouchers_never_collide_with_existing_codes(): void
    {
        $promotion = $this->makePromotion();
        $this->actingAsRole('owner');

        Voucher::create([
            'tenant_id' => $this->tenant->id,
            'promotion_id' => $promotion->id,
            'code' => 'TATA-AAAA',
            'discount_type' => 'percentage',
            'discount_value' => 10,
            'status' => 'active',
            'created_at' => now(),
        ]);

        $this->postJson('/api/v1/admin/promotions/'.$promotion->id.'/vouchers/generate', [
            'count' => 15,
            'prefix' => 'TATA',
        ])->assertCreated();

        $collision = Voucher::query()->where('promotion_id', $promotion->id)->pluck('code');
        $this->assertSame(16, $collision->count());
        $this->assertSame(16, $collision->unique()->count());
    }

    public function test_admin_can_list_vouchers_with_filters(): void
    {
        $promotion = $this->makePromotion();
        $voucherA = Voucher::factory()->for($this->tenant)->create(['code' => 'TATA-1111', 'status' => 'active']);
        Voucher::factory()->for($this->tenant)->for($promotion)->create(['code' => 'TATA-2222', 'status' => 'disabled']);
        $this->actingAsRole('manager');

        $this->getJson('/api/v1/admin/vouchers?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', $voucherA->code);

        $this->getJson('/api/v1/admin/vouchers?promotion_id='.$promotion->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'disabled');

        $this->getJson('/api/v1/admin/vouchers?search=1111')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_voucher_generate_requires_promotion_role(): void
    {
        $promotion = $this->makePromotion();
        $this->actingAsRole('sales');

        $this->postJson('/api/v1/admin/promotions/'.$promotion->id.'/vouchers/generate', ['count' => 1])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_voucher_codes_are_isolated_per_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        $promotion = Promotion::factory()->for($otherTenant)->create();

        Voucher::factory()->for($otherTenant)->create(['code' => 'TATA-ZZZZ']);

        $this->actingAsRole('owner');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/admin/promotions/'.$promotion->id.'/vouchers/generate', [
                'count' => 1,
                'prefix' => 'TATA',
            ])->assertStatus(404);
        }

        $this->assertSame(1, Voucher::query()->withoutGlobalScope('tenant')->where('tenant_id', $otherTenant->id)->count());
    }

    public function test_generated_code_is_url_safe_and_unique_in_db(): void
    {
        $promotion = $this->makePromotion();
        $this->actingAsRole('owner');

        $this->postJson('/api/v1/admin/promotions/'.$promotion->id.'/vouchers/generate', [
            'count' => 10,
            'prefix' => 'PROMO-2026',
        ])->assertCreated();

        $codes = Voucher::query()->pluck('code');

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^PROMO-2026-[A-Z0-9]{4}$/', $code);
            $this->assertSame(1, Voucher::query()->withoutGlobalScope('tenant')
                ->where('tenant_id', $this->tenant->id)
                ->where('code', $code)
                ->count());
        }
    }
}
