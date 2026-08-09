<?php

namespace App\Services\Notification;

use App\Mail\NotificationMail;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Webhook\OutboundWebhookService;
use Illuminate\Support\Facades\Mail;

class NotificationService
{
    public function __construct(private readonly OutboundWebhookService $webhooks) {}

    /**
     * Kirim notifikasi ke user.
     *
     * Channel:
     * - `dashboard` — tersimpan, `sent_at` langsung diisi (tampil di app).
     * - `email` — kirim via Mail, `sent_at` diisi setelah berhasil.
     * - `whatsapp` — tercatat `queued` (pengiriman WA riil menunggu integrasi
     *   provider di Sprint 12), `sent_at` tetap null.
     * - `webhook` — diteruskan ke webhook keluar tenant (`notification.sent`),
     *   `sent_at` diisi kalau terkirim 2xx.
     *
     * @param  array<string, mixed>  $data
     */
    public function notify(
        string $tenantId,
        string|User $userId,
        string $type,
        string $title,
        ?string $body = null,
        array $data = [],
        string $channel = 'dashboard'
    ): Notification {
        $recipient = $userId instanceof User ? $userId : User::find($userId);
        $recipientId = $userId instanceof User ? $userId->id : (string) $userId;

        $notification = Notification::create([
            'tenant_id' => $tenantId,
            'user_id' => $recipientId,
            'channel' => $channel,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => [...$data, 'delivery' => $this->deliveryState($channel)],
            'sent_at' => $channel === 'dashboard' ? now() : null,
        ]);

        if ($channel === 'email' && $recipient?->email) {
            Mail::to($recipient->email)->send(
                new NotificationMail($title, $body, $data)
            );
            $notification->forceFill(['sent_at' => now()])->save();
        }

        if ($channel === 'webhook') {
            $tenant = Tenant::query()->find($tenantId);

            $sent = $tenant ? $this->webhooks->dispatch($tenant, 'notification.sent', [
                'notification_id' => $notification->id,
                'user_id' => $recipientId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
            ]) : false;

            $notification->forceFill(['sent_at' => $sent ? now() : null])->save();
        }

        return $notification;
    }

    private function deliveryState(string $channel): string
    {
        return match ($channel) {
            'email' => 'sent',
            'whatsapp' => 'queued',
            'webhook' => 'queued',
            default => 'delivered',
        };
    }
}
