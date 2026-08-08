<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Tenant;
use App\Services\Lead\LeadScoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadScoringTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private LeadScoringService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
        $this->service = new LeadScoringService;
    }

    private function makeLead(array $customerAttrs = [], array $leadAttrs = []): Lead
    {
        $customer = Customer::factory()->for($this->tenant)->create([
            'name' => 'Budi',
            'phone' => '6281200000001',
            ...$customerAttrs,
        ]);

        return Lead::factory()->for($customer)->create([
            'source' => 'api',
            ...$leadAttrs,
        ]);
    }

    public function test_empty_lead_scores_cold(): void
    {
        $lead = $this->makeLead(['email' => null, 'consent_marketing' => false]);

        $result = $this->service->score($lead);

        $this->assertSame(0, $result['score']);
        $this->assertSame('COLD', $result['temperature']);
    }

    public function test_basic_profile_scores_warm(): void
    {
        $lead = $this->makeLead([
            'email' => 'budi@example.com',
            'location' => 'Jakarta',
        ], ['source' => 'form']);

        $result = $this->service->score($lead);

        $this->assertSame(20, $result['score']);
        $this->assertSame('COLD', $result['temperature']);
    }

    public function test_calculator_completion_scores_warm(): void
    {
        $lead = $this->makeLead(
            ['email' => null, 'consent_marketing' => false],
            ['product_id' => null, 'variant_id' => null]
        );

        $result = $this->service->score($lead, ['calculator_completed' => true]);

        $this->assertSame(20, $result['score']);
        $this->assertSame('COLD', $result['temperature']);
    }

    public function test_high_engagement_scores_hot(): void
    {
        $lead = $this->makeLead([
            'email' => 'budi@example.com',
            'location' => 'Jakarta',
        ]);

        $result = $this->service->score($lead, ['calculator_completed' => true]);

        $this->assertSame(35, $result['score']);
        $this->assertSame('WARM', $result['temperature']);
    }

    public function test_temperature_boundaries(): void
    {
        $this->assertSame('COLD', $this->service->temperature(0));
        $this->assertSame('COLD', $this->service->temperature(29));
        $this->assertSame('WARM', $this->service->temperature(30));
        $this->assertSame('WARM', $this->service->temperature(59));
        $this->assertSame('HOT', $this->service->temperature(60));
        $this->assertSame('HOT', $this->service->temperature(100));
    }

    public function test_score_capped_at_max(): void
    {
        $this->tenant->forceFill([
            'settings' => ['scoring_weights' => ['has_email' => 500]],
        ])->save();

        $lead = $this->makeLead(['email' => 'budi@example.com']);

        $result = $this->service->score($lead);

        $this->assertSame(100, $result['score']);
        $this->assertSame('HOT', $result['temperature']);
    }

    public function test_tenant_weights_override_defaults(): void
    {
        $this->tenant->forceFill([
            'settings' => ['scoring_weights' => ['has_email' => 50]],
        ])->save();

        $lead = $this->makeLead(['email' => 'budi@example.com']);

        $result = $this->service->score($lead);

        $this->assertSame(55, $result['score']);
        $this->assertSame('WARM', $result['temperature']);
    }

    public function test_apply_records_score_and_updates_lead(): void
    {
        $lead = $this->makeLead(
            ['email' => 'budi@example.com', 'location' => 'Jakarta'],
            ['source' => 'form']
        );

        $result = $this->service->apply($lead);

        $this->assertSame(20, $result['score']);
        $this->assertSame('COLD', $result['temperature']);
        $this->assertSame(20, $lead->fresh()->score);

        $this->assertDatabaseHas('lead_scores', [
            'lead_id' => $lead->id,
            'resulting_score' => 20,
        ]);
    }
}
