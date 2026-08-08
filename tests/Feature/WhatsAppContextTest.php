<?php

namespace Tests\Feature;

use App\Models\Calculator;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Calculator\CalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsAppContextTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create([
            'settings' => ['whatsapp_phone' => '628123456789'],
        ]);
    }

    public function test_context_builds_url_with_tenant_phone_and_message(): void
    {
        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/whatsapp/context', [
                'customer_name' => 'Budi',
            ])
            ->assertOk()
            ->assertJsonPath('data.url', 'https://wa.me/628123456789?text='.rawurlencode('Halo, saya Budi, saya tertarik dengan produk Anda, bisa minta informasi lebih lanjut?.'))
            ->assertJsonPath('data.message', 'Halo, saya Budi, saya tertarik dengan produk Anda, bisa minta informasi lebih lanjut?.');
    }

    public function test_context_includes_product_name(): void
    {
        $product = Product::factory()->for($this->tenant)->create(['name' => 'Fronx GLX']);

        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/whatsapp/context', [
                'customer_name' => 'Budi',
                'product_id' => $product->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.message', 'Halo, saya Budi, saya tertarik dengan Fronx GLX, bisa minta informasi lebih lanjut?.');
    }

    public function test_context_includes_calculator_result(): void
    {
        $calculator = Calculator::factory()->for($this->tenant)->credit()->create();
        $result = (new CalculatorService)->run($calculator, [
            'price' => 249500000,
            'dp' => 50000000,
            'tenor' => 60,
            'interest' => 6.5,
        ]);

        $this->withHeader('X-Tenant-ID', $this->tenant->id)
            ->postJson('/api/v1/whatsapp/context', [
                'customer_name' => 'Budi',
                'calculator_session_id' => $result['session_id'],
            ])
            ->assertOk()
            ->assertJsonPath('data.url', 'https://wa.me/628123456789?text='.rawurlencode('Halo, saya Budi, saya tertarik dengan produk Anda, hasil kalkulasi: cicilan ±Rp3.903.447/bulan, bisa minta informasi lebih lanjut?.'))
            ->assertJsonPath('data.message', 'Halo, saya Budi, saya tertarik dengan produk Anda, hasil kalkulasi: cicilan ±Rp3.903.447/bulan, bisa minta informasi lebih lanjut?.');
    }

    public function test_context_without_tenant_phone_uses_default(): void
    {
        $tenantNoPhone = Tenant::factory()->create();

        $this->withHeader('X-Tenant-ID', $tenantNoPhone->id)
            ->postJson('/api/v1/whatsapp/context', [])
            ->assertOk()
            ->assertJsonPath('data.url', 'https://wa.me/6280000000000?text='.rawurlencode('Halo, saya ingin bertanya, saya tertarik dengan produk Anda, bisa minta informasi lebih lanjut?.'));
    }
}
