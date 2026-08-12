<?php

namespace App\Jobs;

use App\Models\CrmDelivery;
use App\Services\Crm\CrmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Kirim event CRM keluar (§78, Sprint 13) dengan retry + backoff penuh.
 * Hasil tiap attempt tercatat di crm_deliveries. Gagal final → status
 * failed tetap tersimpan (observable, tidak dibuang diam-diam).
 */
class DispatchCrmEventJob implements ShouldQueue
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

    public function __construct(public CrmDelivery $delivery) {}

    public function handle(CrmService $crm): void
    {
        $crm->syncNow($this->delivery);
    }
}
