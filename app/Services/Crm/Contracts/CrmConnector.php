<?php

namespace App\Services\Crm\Contracts;

use App\Models\Tenant;

/**
 * Konektor CRM keluar (§78, Sprint 13) — driver diarahkan via CRM_DRIVER
 * (echo untuk dev/test, http untuk generic webhook, hubspot untuk API
 * HubSpot). Seluruh driver wajib throw RuntimeException saat gagal supaya
 * DispatchCrmEventJob bisa retry + mencatat di crm_deliveries.
 */
interface CrmConnector
{
    /**
     * @param  array<string, mixed>  $payload  payload baku CrmEventFactory
     * @return array{endpoint: string, http_status: ?int}
     *
     * @throws \RuntimeException saat provider menolak/gagal
     */
    public function sync(Tenant $tenant, string $event, array $payload): array;
}
