<?php

namespace App\Jobs;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Kirim webhook keluar (§77, §140) dengan retry + backoff penuh.
 * Gagal 3x → event dianggap gagal tercatat di log (tidak dibuang diam-diam).
 */
class DispatchOutboundWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    /** @return int[] */
    public function backoff(): array
    {
        return [15, 60, 300, 900];
    }

    public function __construct(
        public string $tenantId,
        public string $event,
        public array $data,
        public ?string $secret = null,
        public ?string $url = null,
    ) {}

    public function handle(): void
    {
        if (! $this->url) {
            $tenant = Tenant::query()
                ->withoutGlobalScope('tenant')
                ->find($this->tenantId);

            $settings = $tenant?->settings['webhook'] ?? null;

            if (! is_array($settings) || empty($settings['url'])) {
                $this->delete();

                return;
            }

            $this->url = (string) $settings['url'];
            $this->secret = (string) ($settings['secret'] ?? '');
        }

        $payload = [
            'event' => $this->event,
            'tenant_id' => $this->tenantId,
            'data' => $this->data,
            'sent_at' => now()->toIso8601String(),
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-TataSales-Signature' => hash_hmac('sha256', (string) $body, (string) $this->secret),
                ])
                ->withBody((string) $body, 'application/json')
                ->post($this->url);

            if ($response->successful()) {
                return;
            }

            Log::warning('webhook.outbound.failed', [
                'tenant_id' => $this->tenantId,
                'event' => $this->event,
                'status' => $response->status(),
                'attempt' => $this->attempts(),
            ]);

            throw new \RuntimeException('Webhook keluar gagal HTTP '.$response->status());
        } catch (\Throwable $e) {
            Log::warning('webhook.outbound.error', [
                'tenant_id' => $this->tenantId,
                'event' => $this->event,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            throw $e;
        }
    }
}