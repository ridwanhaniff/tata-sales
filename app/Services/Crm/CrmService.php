<?php

namespace App\Services\Crm;

use App\Jobs\DispatchCrmEventJob;
use App\Models\CrmDelivery;
use App\Models\Tenant;
use App\Services\Crm\Contracts\CrmConnector;
use App\Services\Crm\Exceptions\CrmConnectorException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pusat event CRM keluar (§78, Sprint 13) — satu jalur: payload baku dari
 * CrmEventFactory → CrmDelivery (pending) → DispatchCrmEventJob → driver
 * CrmConnector → update delivery (sent/failed). Skips (tanpa log) saat
 * konektor belum dikonfigurasi tenant.
 */
class CrmService
{
    public function __construct(
        private readonly CrmConnector $connector,
        private readonly CrmEventFactory $factory,
    ) {}

    public function factory(): CrmEventFactory
    {
        return $this->factory;
    }

    /**
     * @param  array<string, mixed>  $payload  payload baku CrmEventFactory
     */
    public function dispatch(Tenant $tenant, string $event, array $payload): ?CrmDelivery
    {
        if (! $this->configured($tenant)) {
            Log::warning('crm.dispatch_skipped', [
                'tenant_id' => $tenant->id,
                'event' => $event,
            ]);

            return null;
        }

        $delivery = CrmDelivery::create([
            'tenant_id' => $tenant->id,
            'event' => $event,
            'provider' => (string) config('crm.driver', 'echo'),
            'endpoint' => $this->endpointFor($tenant),
            'status' => CrmDelivery::STATUS_PENDING,
            'payload' => $payload,
        ]);

        dispatch(new DispatchCrmEventJob($delivery));

        return $delivery;
    }

    /**
     * Sinkronkan satu delivery (dipanggil job; juga untuk CLI/test). Gagal →
     * delivery diflag failed lalu exception diteruskan supaya job retry.
     */
    public function syncNow(CrmDelivery $delivery): void
    {
        $tenant = Tenant::query()
            ->withoutGlobalScope('tenant')
            ->find($delivery->tenant_id);

        if (! $tenant) {
            $delivery->markFailed('Tenant tidak ditemukan.');

            return;
        }

        $delivery->forceFill([
            'attempt' => $delivery->attempt + 1,
            'updated_at' => now(),
        ])->save();

        try {
            $result = $this->connector->sync($tenant, $delivery->event, $delivery->payload);
            $delivery->markSent($result['http_status']);
        } catch (Throwable $e) {
            $delivery->markFailed(
                $e->getMessage(),
                $e instanceof CrmConnectorException ? $e->httpStatus : null
            );
            throw $e;
        }
    }

    private function configured(Tenant $tenant): bool
    {
        return match ((string) config('crm.driver', 'echo')) {
            'http' => (bool) ($tenant->settings['webhook']['url'] ?? null),
            'hubspot' => (string) config('crm.hubspot.api_key', '') !== '',
            default => true, // echo selalu tersedia (dev/test)
        };
    }

    private function endpointFor(Tenant $tenant): ?string
    {
        return match ((string) config('crm.driver', 'echo')) {
            'http' => (string) ($tenant->settings['webhook']['url'] ?? '') ?: null,
            'hubspot' => (string) config('crm.hubspot.base_url', ''),
            default => 'echo://local',
        };
    }
}
