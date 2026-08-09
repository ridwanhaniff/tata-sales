<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Lead;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\SalesTeam;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Lead\AssignmentService;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssignmentV2Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private AssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('currentTenant', $this->tenant);
        $this->seed([PipelineStageSeeder::class]);

        $this->service = app(AssignmentService::class);
    }

    private function makeLead(): Lead
    {
        $customer = Customer::factory()->for($this->tenant)->create([
            'phone' => fake()->unique()->numerify('62812########'),
        ]);

        return Lead::factory()->for($customer)->create(['status' => 'NEW']);
    }

    /**
     * @return array{lead: Lead, salesA: User, salesB: User}
     */
    private function twoSalesLeads(): array
    {
        $salesA = User::factory()->for($this->tenant)->role('sales')->create();
        $salesB = User::factory()->for($this->tenant)->role('sales')->create();

        $customerA = Customer::factory()->for($this->tenant)->create(['phone' => '6281290000001']);
        $customerB = Customer::factory()->for($this->tenant)->create(['phone' => '6281290000002']);

        $leadA = Lead::factory()->for($customerA)->create(['status' => 'NEW', 'assigned_to' => $salesA->id]);
        $leadB = Lead::factory()->for($customerB)->create(['status' => 'NEW', 'assigned_to' => $salesB->id]);

        return [$leadA, $salesA, $salesB];
    }

    public function test_default_round_robin_balances_across_sales(): void
    {
        [$leadA, $salesA, $salesB] = $this->twoSalesLeads();
        $leadC = $this->makeLead();

        $result = $this->service->assign($leadC);

        $this->assertSame('round_robin', $result['method']);
        $this->assertSame($salesA->id, $result['sales']->id);
    }

    public function test_product_method_assigns_to_team_of_product_category(): void
    {
        $this->tenant->forceFill(['settings' => ['assignment' => ['method' => 'product']]])->save();

        $category = ProductCategory::factory()->for($this->tenant)->create();
        $otherCategory = ProductCategory::factory()->for($this->tenant)->create();

        $salesInTeam = User::factory()->for($this->tenant)->role('sales')->create();
        $team = SalesTeam::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Seller Motor',
            'product_category_id' => $category->id,
        ]);
        $team->members()->attach($salesInTeam->id, ['tenant_id' => $this->tenant->id]);

        $outsider = User::factory()->for($this->tenant)->role('sales')->create();

        $product = Product::factory()->for($this->tenant)->create(['category_id' => $category->id]);
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => '6281290000003']);
        $lead = Lead::factory()->for($customer)->create(['status' => 'NEW', 'product_id' => $product->id]);

        $result = $this->service->assign($lead);

        $this->assertSame('product', $result['method']);
        $this->assertSame($salesInTeam->id, $result['sales']->id);
    }

    public function test_product_method_falls_back_to_round_robin_when_no_category_match(): void
    {
        $this->tenant->forceFill(['settings' => ['assignment' => ['method' => 'product']]])->save();

        $sales = User::factory()->for($this->tenant)->role('sales')->create();
        $otherCategory = ProductCategory::factory()->for($this->tenant)->create();
        $team = SalesTeam::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Team A',
            'product_category_id' => $otherCategory->id,
        ]);
        $team->members()->attach($sales->id, ['tenant_id' => $this->tenant->id]);

        $customer = Customer::factory()->for($this->tenant)->create(['phone' => '6281290000004']);
        $lead = Lead::factory()->for($customer)->create(['status' => 'NEW']);

        $result = $this->service->assign($lead);

        $this->assertSame('round_robin', $result['method']);
        $this->assertSame($sales->id, $result['sales']->id);
    }

    public function test_location_method_assigns_to_team_of_region(): void
    {
        $this->tenant->forceFill(['settings' => ['assignment' => ['method' => 'location']]])->save();

        $jakartaSales = User::factory()->for($this->tenant)->role('sales')->create();
        $jakartaTeam = SalesTeam::create(['tenant_id' => $this->tenant->id, 'name' => 'Jakarta', 'region' => 'Jakarta Selatan']);
        $jakartaTeam->members()->attach($jakartaSales->id, ['tenant_id' => $this->tenant->id]);

        $outsider = User::factory()->for($this->tenant)->role('sales')->create();

        $customer = Customer::factory()->for($this->tenant)->create([
            'phone' => '6281290000005',
            'location' => 'Jakarta Selatan',
        ]);
        $lead = Lead::factory()->for($customer)->create(['status' => 'NEW']);

        $result = $this->service->assign($lead);

        $this->assertSame('location', $result['method']);
        $this->assertSame($jakartaSales->id, $result['sales']->id);
    }

    public function test_workload_method_picks_least_loaded(): void
    {
        $this->tenant->forceFill(['settings' => ['assignment' => ['method' => 'workload']]])->save();

        $busy = User::factory()->for($this->tenant)->role('sales')->create();
        $free = User::factory()->for($this->tenant)->role('sales')->create();

        for ($i = 0; $i < 3; $i++) {
            $customer = Customer::factory()->for($this->tenant)->create(['phone' => '62812910000'.$i]);
            Lead::factory()->for($customer)->create(['status' => 'NEW', 'assigned_to' => $busy->id]);
        }

        $customer = Customer::factory()->for($this->tenant)->create(['phone' => '6281291000099']);
        $lead = Lead::factory()->for($customer)->create(['status' => 'NEW']);

        $this->assertSame('workload', $this->service->assign($lead)['method']);
        $this->assertSame($free->id, $this->service->assign($lead)['sales']->id);
    }

    public function test_round_robin_tiebreak_by_oldest_assignment(): void
    {
        $salesA = User::factory()->for($this->tenant)->role('sales')->create();
        $salesB = User::factory()->for($this->tenant)->role('sales')->create();

        $customer = Customer::factory()->for($this->tenant)->create(['phone' => '6281292000001']);
        $lead = Lead::factory()->for($customer)->create(['status' => 'NEW']);

        $this->service->assign($lead);

        $older = $lead->fresh()->assigned_to;

        $olderLead = Lead::factory()->for($customer)->create(['status' => 'NEW']);

        $result = $this->service->assign($olderLead);

        $this->assertSame($salesA->id === $older ? $salesB->id : $salesA->id, $result['sales']->id);
    }
}
