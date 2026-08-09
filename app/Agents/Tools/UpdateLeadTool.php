<?php

namespace App\Agents\Tools;

use App\Agents\Tools\Contracts\Tool;
use App\Models\Lead;
use App\Models\Product;
use App\Services\Lead\LeadService;
use Illuminate\Support\Arr;

/**
 * update_lead (§5 roster: Qualification Agent). Whitelist field ketat:
 * - estimated_value  → leads.estimated_value (dari angka yang diucapkan customer)
 * - product_id       → leads.product_id (produk published milik tenant)
 * - customer_location→ customers.location
 * - timeline         → catatan "timeline" (string) di context + notes
 *
 * Yang TIDAK boleh: status, harga custom, diskon custom — tidak ada
 * jalurnya di tool ini (guardrail §31/§118).
 */
class UpdateLeadTool implements Tool
{
    public function __construct(private readonly LeadService $leads) {}

    public function name(): string
    {
        return 'update_lead';
    }

    public function description(): string
    {
        return 'Simpan data kualifikasi lead ke CRM: estimated_value (budget), product_id (produk diminati), customer_location (lokasi), atau timeline (rencana waktu). Hanya menulis field yang memang valid & sesuai tenant.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lead_id' => ['type' => 'string'],
                'fields' => [
                    'type' => 'object',
                    'properties' => [
                        'estimated_value' => ['type' => 'number'],
                        'product_id' => ['type' => 'string'],
                        'customer_location' => ['type' => 'string'],
                        'timeline' => ['type' => 'string'],
                    ],
                ],
            ],
            'required' => ['lead_id', 'fields'],
        ];
    }

    public function execute(array $arguments): array
    {
        $tenantId = app()->bound('currentTenant') ? app('currentTenant')->id : null;

        $lead = Lead::query()->with('customer')->find(Arr::get($arguments, 'lead_id'));

        if (! $lead) {
            return ['done' => false, 'reason' => 'Lead tidak ditemukan.'];
        }

        if ($tenantId && $lead->tenant_id !== $tenantId) {
            return ['done' => false, 'reason' => 'Lead tidak ditemukan.'];
        }

        $fields = (array) (Arr::get($arguments, 'fields') ?? []);
        $updated = [];

        if (Arr::has($fields, 'estimated_value')) {
            $value = Arr::get($fields, 'estimated_value');

            if (! is_numeric($value) || (float) $value <= 0) {
                return ['done' => false, 'reason' => 'estimated_value harus angka positif.'];
            }

            $lead->forceFill(['estimated_value' => (float) $value])->save();
            $updated['estimated_value'] = (float) $value;
        }

        if (Arr::has($fields, 'product_id')) {
            $product = Product::query()
                ->where('status', 'published')
                ->whereNotNull('published_at')
                ->find(Arr::get($fields, 'product_id'));

            if (! $product) {
                return ['done' => false, 'reason' => 'Produk tidak tersedia.'];
            }

            $lead->forceFill(['product_id' => $product->id])->save();
            $updated['product_id'] = $product->id;
        }

        if (Arr::has($fields, 'customer_location')) {
            $location = trim((string) Arr::get($fields, 'customer_location', ''));

            if ($location !== '') {
                $lead->customer?->forceFill(['location' => mb_substr($location, 0, 255)])->save();
                $updated['customer_location'] = $location;
            }
        }

        if (Arr::has($fields, 'timeline')) {
            $timeline = trim((string) Arr::get($fields, 'timeline', ''));

            if ($timeline !== '') {
                $lead->notes()->create([
                    'tenant_id' => $lead->tenant_id,
                    'lead_id' => $lead->id,
                    'customer_id' => $lead->customer_id,
                    'content' => 'Timeline (AI): '.mb_substr($timeline, 0, 500),
                ]);
                $updated['timeline'] = $timeline;
            }
        }

        if ($updated === []) {
            return ['done' => false, 'reason' => 'Tidak ada field valid yang dikirimkan.'];
        }

        $this->leads->logEvent($lead, 'qualification_updated', $updated);

        return ['done' => true, 'applied_fields' => $updated];
    }
}
