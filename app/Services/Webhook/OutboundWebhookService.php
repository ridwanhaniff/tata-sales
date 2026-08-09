<?php

namespace App\Services\Webhook;

use App\Jobs\DispatchOutboundWebhookJob;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Webhook keluar (§77, §140) — dikonfigurasi per tenant via
 * `tenants.settings.webhook.url` + `.webhook.secret`.
 *
 * Payload ditandatangani HMAC-SHA256, header `X-TataSales-Signature`.
 * Pengiriman lewat DispatchOutboundWebhookJob (queue) dengan retry+backoff.
 */
class OutboundWebhookService
{
    public function dispatch(Tenant $tenant, string $event, array $data): bool
    {
        $settings = $tenant->settings['webhook'] ?? null;

        if (! is_array($settings) || empty($settings['url'])) {
            return false;
        }

        dispatch(new DispatchOutboundWebhookJob(
            tenantId: $tenant->id,
            event: $event,
            data: $data,
            secret: (string) ($settings['secret'] ?? ''),
            url: (string) $settings['url'],
        ));

        return true;
    }

    /**
     * Kirim sinkron (untuk test/CLI): tanpa antrian, sekali coba.
     */
    public function sendNow(Tenant $tenant, string $event, array $data): bool
    {
        $settings = $tenant->settings['webhook'] ?? null;

        if (! is_array($settings) || empty($settings['url'])) {
            return false;
        }

        $secret = (string) ($settings['secret'] ?? '');

        $payload = [
            'event' => $event,
            'tenant_id' => $tenant->id,
            'data' => $data,
            'sent_at' => now()->toIso8601String(),
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-TataSales-Signature' => hash_hmac('sha256', $body, $secret),
                ])
                ->withBody($body, 'application/json')
                ->post((string) $settings['url']);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('webhook.outbound.send_now_failed', [
                'tenant_id' => $tenant->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
