<?php

namespace Tests\Feature;

use App\Models\Calculator;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Calculator\CalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LeadSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
    }

    private function postWithTenant(string $uri, array $payload = []): TestResponse
    {
        return $this->withHeader('X-Tenant-ID', $this->tenant->id)->postJson($uri, $payload);
    }

    private function makeProduct(): Product
    {
        return Product::factory()->for($this->tenant)->create(['base_price' => 249500000]);
    }

    public function test_submit_creates_customer_lead_scores_and_assigns(): void
    {
        $sales = User::factory()->for($this->tenant)->role('sales')->create();
        $product = $this->makeProduct();

        $this->postWithTenant('/api/v1/leads', [
            'customer' => ['name' => 'Budi Santoso', 'phone' => '081298765432', 'email' => 'budi@example.com'],
            'product_id' => $product->id,
            'source' => 'form',
            'consent_marketing' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'NEW')
            ->assertJsonPath('data.assigned_to.name', $sales->name)
            ->assertJsonStructure(['data' => ['lead_id', 'temperature', 'score']]);

        $this->assertDatabaseHas('customers', [
            'tenant_id' => $this->tenant->id,
            'phone' => '6281298765432',
            'consent_marketing' => true,
        ]);
        $this->assertDatabaseHas('leads', [
            'tenant_id' => $this->tenant->id,
            'status' => 'NEW',
            'score' => 25,
            'temperature' => 'COLD',
        ]);
        $this->assertDatabaseHas('lead_events', ['event_type' => 'lead_created']);
        $this->assertDatabaseHas('lead_events', ['event_type' => 'sales_assigned']);
        $this->assertDatabaseHas('lead_assignments', ['method' => 'round_robin']);
        $this->assertDatabaseHas('notifications', ['type' => 'new_lead']);
    }

    public function test_submit_with_calculator_session_links_and_scores_higher(): void
    {
        User::factory()->for($this->tenant)->role('sales')->create();

        $calculator = Calculator::factory()->for($this->tenant)->credit()->create();
        $result = (new CalculatorService)->run($calculator, [
            'price' => 249500000,
            'dp' => 50000000,
            'tenor' => 60,
            'interest' => 6.5,
        ]);

        $product = $this->makeProduct();

        $this->postWithTenant('/api/v1/leads', [
            'customer' => ['name' => 'Budi Santoso', 'phone' => '081298765432'],
            'product_id' => $product->id,
            'calculator_session_id' => $result['session_id'],
            'consent_marketing' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.temperature', 'WARM');

        $lead = Lead::query()->first();
        $this->assertNotNull($lead);
        $this->assertSame(40, $lead->score);

        $this->assertDatabaseHas('calculator_sessions', [
            'id' => $result['session_id'],
            'lead_id' => $lead->id,
        ]);
        $this->assertDatabaseHas('lead_events', ['event_type' => 'calculator_completed']);
    }

    public function test_existing_customer_is_reused(): void
    {
        User::factory()->for($this->tenant)->role('sales')->create();
        Customer::factory()->for($this->tenant)->create(['phone' => '6281298765432', 'name' => 'Lama']);

        $this->postWithTenant('/api/v1/leads', [
            'customer' => ['name' => 'Budi Santoso', 'phone' => '081298765432'],
            'consent_marketing' => false,
        ])->assertCreated();

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseHas('customers', ['phone' => '6281298765432', 'name' => 'Lama']);
        $this->assertDatabaseCount('leads', 1);
    }

    public function test_provider_event_id_is_idempotent(): void
    {
        User::factory()->for($this->tenant)->role('sales')->create();

        $payload = [
            'customer' => ['name' => 'Budi', 'phone' => '081298765432'],
            'provider_event_id' => 'webhook-abc-123',
            'consent_marketing' => true,
        ];

        $this->postWithTenant('/api/v1/leads', $payload)->assertCreated();
        $this->postWithTenant('/api/v1/leads', $payload)->assertOk();

        $this->assertDatabaseCount('leads', 1);
    }

    public function test_invalid_phone_is_rejected(): void
    {
        $this->postWithTenant('/api/v1/leads', [
            'customer' => ['name' => 'Budi', 'phone' => '123'],
            'consent_marketing' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_consent_marketing_is_required(): void
    {
        $this->postWithTenant('/api/v1/leads', [
            'customer' => ['name' => 'Budi', 'phone' => '081298765432'],
        ])
            ->assertStatus(422);
    }

    public function test_submit_is_rate_limited(): void
    {
        $payload = [
            'customer' => ['name' => 'Budi', 'phone' => '081298765432'],
            'consent_marketing' => true,
        ];

        for ($i = 0; $i < 10; $i++) {
            $this->postWithTenant('/api/v1/leads', $payload);
        }

        $this->postWithTenant('/api/v1/leads', $payload)
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED');
    }
}
