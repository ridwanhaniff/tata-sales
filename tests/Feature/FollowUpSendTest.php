<?php

namespace Tests\Feature;

use App\Jobs\SendFollowupJob;
use App\Models\Customer;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FollowUpSendTest extends TestCase
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

    private function makeDueFollowup(array $attributes = []): Followup
    {
        $customer = Customer::factory()->for($this->tenant)->create(['phone' => fake()->unique()->numerify('62812########')]);

        $lead = Lead::factory()->for($customer)->create();

        return Followup::create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'status' => 'pending',
            'channel' => 'whatsapp',
            'scheduled_at' => now()->subMinute(),
            'message' => 'Halo '.$customer->name.', kabar baik?',
            'created_at' => now(),
            ...$attributes,
        ]);
    }

    public function test_command_dispatches_job_for_due_followups(): void
    {
        Queue::fake();

        $this->makeDueFollowup();

        $this->artisan('followups:send')->assertSuccessful();

        Queue::assertPushed(SendFollowupJob::class, 1);
    }

    public function test_command_skips_future_followups(): void
    {
        Queue::fake();

        $this->makeDueFollowup(['scheduled_at' => now()->addHours(2)]);

        $this->artisan('followups:send')->assertSuccessful();

        Queue::assertNotPushed(SendFollowupJob::class);
    }

    public function test_job_marks_followup_sent_and_notifies_sales(): void
    {
        $sales = User::factory()->for($this->tenant)->role('sales')->create(['email' => 'sales@test.dev']);
        $followup = $this->makeDueFollowup(['assigned_to' => $sales->id]);

        SendFollowupJob::dispatchSync($followup->id);

        $fresh = $followup->fresh();
        $this->assertSame('sent', $fresh->status);
        $this->assertNotNull($fresh->sent_at);

        $this->assertDatabaseHas('notifications', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $sales->id,
            'type' => 'followup_sent',
            'channel' => 'dashboard',
        ]);
    }

    public function test_already_sent_followup_is_not_resent(): void
    {
        $followup = $this->makeDueFollowup(['status' => 'sent', 'sent_at' => now()]);

        SendFollowupJob::dispatchSync($followup->id);

        $this->assertSame(0, Notification::count());
        $this->assertSame(1, Followup::query()->where('status', 'sent')->count());
    }

    public function test_without_assignment_no_notification_is_sent(): void
    {
        $followup = $this->makeDueFollowup(['assigned_to' => null]);

        SendFollowupJob::dispatchSync($followup->id);

        $this->assertSame('sent', $followup->fresh()->status);
        $this->assertSame(0, Notification::count());
    }
}
