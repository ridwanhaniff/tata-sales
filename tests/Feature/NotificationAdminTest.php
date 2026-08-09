<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Database\Seeders\PipelineStageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationAdminTest extends TestCase
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

    private function notificationsFor(User $user, int $count = 2): void
    {
        for ($i = 0; $i < $count; $i++) {
            Notification::create([
                'tenant_id' => $this->tenant->id,
                'user_id' => $user->id,
                'channel' => 'dashboard',
                'type' => 'new_lead',
                'title' => 'Lead baru #'.$i,
                'body' => 'Lead menunggu respons.',
                'sent_at' => now(),
            ]);
        }
    }

    public function test_sales_sees_only_own_notifications(): void
    {
        $salesA = $this->actingAsRole('sales');
        $salesB = User::factory()->for($this->tenant)->role('sales')->create();
        $this->notificationsFor($salesA);
        $this->notificationsFor($salesB, 1);

        $this->getJson('/api/v1/admin/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_owner_can_filter_unread_and_by_user(): void
    {
        $this->actingAsRole('owner');
        $sales = User::factory()->for($this->tenant)->role('sales')->create();

        Notification::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $sales->id,
            'channel' => 'dashboard',
            'type' => 'new_lead',
            'title' => 'Alarm',
            'sent_at' => now(),
        ]);
        Notification::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $sales->id,
            'channel' => 'dashboard',
            'type' => 'new_lead',
            'title' => 'Alarm lama',
            'read_at' => now(),
            'sent_at' => now(),
        ]);

        $this->getJson('/api/v1/admin/notifications?unread=1&user_id='.$sales->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_sales_can_mark_own_notification_read(): void
    {
        $sales = $this->actingAsRole('sales');
        $notification = Notification::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $sales->id,
            'channel' => 'dashboard',
            'type' => 'new_lead',
            'title' => 'Alarm',
            'sent_at' => now(),
        ]);

        $this->postJson('/api/v1/admin/notifications/'.$notification->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.read_at', fn ($v) => $v !== null);
    }

    public function test_sales_cannot_mark_foreign_notification_read(): void
    {
        $sales = $this->actingAsRole('sales');
        $other = User::factory()->for($this->tenant)->role('sales')->create();
        $notification = Notification::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $other->id,
            'channel' => 'dashboard',
            'type' => 'new_lead',
            'title' => 'Alarm milik sales lain',
            'sent_at' => now(),
        ]);

        $this->postJson('/api/v1/admin/notifications/'.$notification->id.'/read')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_email_channel_sends_mail_and_records_sent_at(): void
    {
        Mail::fake();

        $this->actingAsRole('manager');
        $sales = User::factory()->for($this->tenant)->role('sales')->create(['email' => 'sales@showroom.dev']);

        $notification = app(NotificationService::class)->notify(
            $this->tenant->id,
            $sales->id,
            'followup_sent',
            'Follow-up terkirim',
            'Kontak {customer} baru saja dikirim.',
            ['lead_id' => 'x'],
            'email'
        );

        Mail::assertSent(NotificationMail::class, function ($mail) use ($sales) {
            return $mail->hasTo($sales->email) && $mail->title === 'Follow-up terkirim';
        });

        $this->assertNotNull($notification->fresh()->sent_at);
        $this->assertSame('email', $notification->channel);
    }

    public function test_dashboard_notification_does_not_send_email(): void
    {
        Mail::fake();

        $this->actingAsRole('owner');
        $sales = User::factory()->for($this->tenant)->role('sales')->create(['email' => 'sales2@showroom.dev']);

        app(NotificationService::class)->notify(
            $this->tenant->id,
            $sales,
            'new_lead',
            'Lead baru',
            'Ayo respons.',
            [],
            'dashboard'
        );

        Mail::assertNotSent(NotificationMail::class);
    }

    public function test_notifications_are_isolated_per_tenant(): void
    {
        $this->actingAsRole('owner');
        $foreign = Tenant::factory()->create();
        $foreignUser = User::factory()->for($foreign)->role('sales')->create();
        $foreignNotification = Notification::create([
            'tenant_id' => $foreign->id,
            'user_id' => $foreignUser->id,
            'channel' => 'dashboard',
            'type' => 'new_lead',
            'title' => 'Rahasia tenant lain',
            'sent_at' => now(),
        ]);

        $this->getJson('/api/v1/admin/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->postJson('/api/v1/admin/notifications/'.$foreignNotification->id.'/read')
            ->assertStatus(404);
    }

    public function test_notifications_for_lead_creation_are_created(): void
    {
        $this->actingAsRole('owner');
        $sales = User::factory()->for($this->tenant)->role('sales')->create();

        $lead = Lead::factory()->for(
            Customer::factory()->for($this->tenant)->create(['phone' => '6281299112233'])
        )->create(['assigned_to' => $sales->id]);

        app(NotificationService::class)->notify(
            $this->tenant->id,
            $sales->id,
            'new_lead',
            'Lead baru menunggu respons',
            'Customer baru • '.$lead->customer->name,
            ['lead_id' => $lead->id]
        );

        $this->assertDatabaseHas('notifications', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $sales->id,
            'type' => 'new_lead',
        ]);
    }

    public function test_owner_can_broadcast_notification_via_api(): void
    {
        $this->actingAsRole('owner');
        $salesA = User::factory()->for($this->tenant)->role('sales')->create();
        $salesB = User::factory()->for($this->tenant)->role('sales')->create();

        $this->postJson('/api/v1/admin/notifications', [
            'user_ids' => [$salesA->id, $salesB->id],
            'title' => 'Pengumuman kerja bakti',
            'body' => 'Hari Sabtu pukul 8.',
            'type' => 'admin_message',
        ])
            ->assertCreated()
            ->assertJsonCount(2, 'data');

        $this->assertDatabaseHas('notifications', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $salesA->id,
            'type' => 'admin_message',
            'channel' => 'dashboard',
        ]);
        $this->assertDatabaseHas('notifications', [
            'tenant_id' => $this->tenant->id,
            'user_id' => $salesB->id,
            'type' => 'admin_message',
        ]);
    }

    public function test_sales_cannot_broadcast_notifications(): void
    {
        $this->actingAsRole('sales');

        $this->postJson('/api/v1/admin/notifications', [
            'user_ids' => [auth()->id()],
            'title' => 'Jancok',
        ])->assertStatus(403);
    }
}
