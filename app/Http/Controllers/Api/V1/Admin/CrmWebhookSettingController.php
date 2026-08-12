<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\UpdateCrmWebhookRequest;
use App\Models\Tenant;
use App\Services\Crm\CrmService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Konfigurasi webhook CRM per tenant (§77-78, Sprint 13): endpoint keluar
 * (url+secret) dan secret inbound untuk /webhooks/crm. Tersimpan di
 * `tenants.settings.webhook`. Endpoint /test mengirim event `test.ping`
 * sinkron ke konektor aktif dan mengembalikan delivery log hasilnya.
 */
class CrmWebhookSettingController extends Controller
{
    public function __construct(private readonly CrmService $crm) {}

    public function show(): JsonResponse
    {
        $tenant = $this->tenant();

        $settings = (array) ($tenant->settings['webhook'] ?? []);

        return ApiResponse::success([
            'url' => $settings['url'] ?? null,
            'secret_configured' => ! empty($settings['secret']),
            'inbound_secret_configured' => ! empty($settings['inbound_secret']),
            'driver' => (string) config('crm.driver', 'echo'),
            'endpoint' => $this->endpointHint($tenant),
        ]);
    }

    public function update(UpdateCrmWebhookRequest $request): JsonResponse
    {
        $tenant = $this->tenant();

        $settings = (array) ($tenant->settings['webhook'] ?? []);
        $validated = $request->validated();

        foreach (['url', 'secret', 'inbound_secret'] as $key) {
            if (array_key_exists($key, $validated)) {
                $settings[$key] = $validated[$key];
            }
        }

        $tenant->forceFill(['settings' => array_merge((array) $tenant->settings, ['webhook' => $settings])])->save();

        return ApiResponse::success([
            'url' => $settings['url'] ?? null,
            'secret_configured' => ! empty($settings['secret']),
            'inbound_secret_configured' => ! empty($settings['inbound_secret']),
            'driver' => (string) config('crm.driver', 'echo'),
            'endpoint' => $this->endpointHint($tenant),
        ]);
    }

    /**
     * Test koneksi: kirim event test.ping sinkron ke konektor aktif.
     */
    public function test(): JsonResponse
    {
        $tenant = $this->tenant();

        $payload = [
            'ping' => true,
            'version' => 'v1',
            'occurred_at' => now()->toIso8601String(),
        ];

        $delivery = $this->crm->dispatch($tenant, 'test.ping', $payload);

        if (! $delivery) {
            return ApiResponse::error(
                'CRM_NOT_CONFIGURED',
                'Konektor CRM belum dikonfigurasi (set webhook url atau CRM_HUBSPOT_API_KEY).',
                422
            );
        }

        try {
            // syncNow agar hasilnya langsung terlihat; job tetap mengikuti di belakang.
            $this->crm->syncNow($delivery);
        } catch (Throwable $e) {
            $message = 'Koneksi gagal: '.$e->getMessage();
        }

        $delivery = $delivery->fresh();

        if (! isset($message)) {
            $message = $delivery->status === 'sent'
                ? 'Koneksi berhasil (HTTP '.($delivery->http_status ?? 'n/a').').'
                : 'Koneksi gagal.';
        }

        return ApiResponse::success([
            'message' => $message,
            'delivery' => [
                'id' => $delivery->id,
                'status' => $delivery->status,
                'http_status' => $delivery->http_status,
                'error' => $delivery->error,
            ],
        ]);
    }

    private function tenant(): Tenant
    {
        /** @var Tenant $tenant */
        $tenant = app('currentTenant');

        return $tenant;
    }

    private function endpointHint(Tenant $tenant): ?string
    {
        $settings = (array) ($tenant->settings['webhook'] ?? []);

        return match ((string) config('crm.driver', 'echo')) {
            'http' => $settings['url'] ?? null,
            'hubspot' => (string) config('crm.hubspot.base_url', ''),
            default => 'echo://local',
        };
    }
}
