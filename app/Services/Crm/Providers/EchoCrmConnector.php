<?php

namespace App\Services\Crm\Providers;

use App\Models\Tenant;
use App\Services\Crm\Contracts\CrmConnector;
use Illuminate\Support\Facades\Log;

/**
 * Driver default (dev/test): tidak memanggil jaringan, hanya mencatat ke
 * log — untuk verifikasi alur end-to-end tanpa akun/endpoint CRM riil.
 */
class EchoCrmConnector implements CrmConnector
{
    public function sync(Tenant $tenant, string $event, array $payload): array
    {
        Log::info('crm.connector.echo', [
            'tenant_id' => $tenant->id,
            'event' => $event,
        ]);

        return [
            'endpoint' => 'echo://local',
            'http_status' => 200,
        ];
    }
}
