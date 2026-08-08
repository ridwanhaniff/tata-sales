<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherPublicTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
        $this->withHeader('X-Tenant-ID', $this->tenant->id);
    }

    private function makeVoucher(array $attributes = []): Voucher
    {
        return Voucher::factory()->for($this->tenant)->create($attributes);
    }

    public function test_public_can_redeem_active_voucher_with_customer(): void
    {
        $voucher = $this->makeVoucher();

        $this->postJson('/api/v1/vouchers/redeem', [
            'code' => $voucher->code,
            'customer' => ['name' => 'Budi Santoso', 'phone' => '6281200000001'],
        ])
            ->assertOk()
            ->assertJsonPath('data.code', $voucher->code)
            ->assertJsonPath('data.usage_count', 1);

        $this->assertDatabaseCount('voucher_usages', 1);
        $this->assertSame(1, $voucher->fresh()->usage_count);

        $customer = Customer::query()->where('phone', '6281200000001')->first();
        $this->assertNotNull($customer);
        $this->assertSame('voucher', $customer->source);
    }

    public function test_same_customer_cannot_redeem_beyond_per_customer_limit(): void
    {
        $voucher = $this->makeVoucher(['per_customer_limit' => 1]);

        $this->postJson('/api/v1/vouchers/redeem', [
            'code' => $voucher->code,
            'customer' => ['name' => 'Siti', 'phone' => '6281200000002'],
        ])->assertOk();

        $this->postJson('/api/v1/vouchers/redeem', [
            'code' => $voucher->code,
            'customer' => ['name' => 'Siti', 'phone' => '6281200000002'],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_usage_limit_blocks_redemption(): void
    {
        $voucher = $this->makeVoucher(['usage_limit' => 1]);

        $this->postJson('/api/v1/vouchers/redeem', [
            'code' => $voucher->code,
            'customer' => ['phone' => '6281200000003'],
        ])->assertOk();

        $this->postJson('/api/v1/vouchers/redeem', [
            'code' => $voucher->code,
            'customer' => ['phone' => '6281200000004'],
        ])->assertStatus(422);
    }

    public function test_expired_voucher_is_rejected(): void
    {
        $voucher = $this->makeVoucher(['expires_at' => now()->subDay()]);

        $this->postJson('/api/v1/vouchers/redeem', ['code' => $voucher->code])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_disabled_voucher_is_rejected(): void
    {
        $voucher = $this->makeVoucher(['status' => 'disabled']);

        $this->postJson('/api/v1/vouchers/redeem', ['code' => $voucher->code])
            ->assertStatus(422);
    }

    public function test_unknown_code_is_rejected(): void
    {
        $this->postJson('/api/v1/vouchers/redeem', ['code' => 'TATA-XXXX'])
            ->assertStatus(422);
    }

    public function test_redemption_without_customer_is_allowed(): void
    {
        $voucher = $this->makeVoucher();

        $this->postJson('/api/v1/vouchers/redeem', ['code' => $voucher->code])
            ->assertOk();

        $this->assertDatabaseHas('voucher_usages', [
            'voucher_id' => $voucher->id,
            'customer_id' => null,
        ]);
    }

    public function test_invalid_customer_phone_is_rejected(): void
    {
        $voucher = $this->makeVoucher();

        $this->postJson('/api/v1/vouchers/redeem', [
            'code' => $voucher->code,
            'customer' => ['phone' => 'abc'],
        ])
            ->assertStatus(422);
    }

    public function test_voucher_is_isolated_per_tenant(): void
    {
        $voucherA = $this->makeVoucher();

        $otherTenant = Tenant::factory()->create();
        $voucherB = Voucher::factory()->for($otherTenant)->create(['code' => 'TATA-BBBB']);

        $this->postJson('/api/v1/vouchers/redeem', ['code' => $voucherB->code])
            ->assertStatus(422);

        $this->postJson('/api/v1/vouchers/redeem', ['code' => $voucherA->code])
            ->assertOk();

        $this->assertSame(0, VoucherUsage::query()->withoutGlobalScope('tenant')
            ->where('tenant_id', $otherTenant->id)
            ->count());
    }
}
