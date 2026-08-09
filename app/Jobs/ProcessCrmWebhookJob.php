<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Services\Lead\LeadService;
use App\Support\PhoneNormalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Proses webhook CRM masuk (§78, Sprint 13): sinkronisasi kontak/lead dari
 * CRM eksternal (HubSpot/Zoho/Lark via konektor). Berbeda dari WA —
 * tanpa consent yang dikonfirmasi, kontak tetap dicatat jadi customer,
 * LEAD hanya dibuat bila consent_marketing=true (sama aturan §91).
 */
class ProcessCrmWebhookJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public WebhookEvent $event) {}

    public function handle(LeadService $leads): void
    {
        try {
            DB::transaction(function () use ($leads) {
                $tenantId = $this->event->tenant_id;

                if (! $tenantId) {
                    throw new \RuntimeException('WebhookEvent tanpa tenant.');
                }

                $tenant = Tenant::query()->find($tenantId);

                if (! $tenant) {
                    throw new \RuntimeException("Tenant {$tenantId} tidak ditemukan.");
                }

                $payload = $this->event->payload;

                $phone = $payload['phone'] ?? null
                    ? PhoneNormalizer::normalize((string) $payload['phone'])
                    : null;

                $customer = $this->resolveCustomer($tenantId, $payload, $phone);

                if (! ($payload['consent_marketing'] ?? ($payload['consent'] ?? false))) {
                    return; // aturan consent — lead tidak dibuat tanpa consent
                }

                $product = null;
                $campaign = null;

                if ($payload['product_ref'] ?? null) {
                    $product = \App\Models\Product::query()
                        ->withoutGlobalScope('tenant')
                        ->where('tenant_id', $tenantId)
                        ->where('id', $payload['product_ref'])
                        ->first();
                }

                if ($payload['campaign_ref'] ?? null) {
                    $campaign = \App\Models\Campaign::query()
                        ->withoutGlobalScope('tenant')
                        ->where('tenant_id', $tenantId)
                        ->where('id', $payload['campaign_ref'])
                        ->first();
                }

                try {
                    $result = $leads->createFromForm([
                        'customer' => [
                            'name' => $payload['name'] ?? $customer->name,
                            'phone' => $customer->phone,
                            'email' => $customer->email ?? null,
                        ],
                        'source' => 'crm',
                        'consent_marketing' => true,
                        'provider_event_id' => $this->event->provider_event_id,
                        'product_id' => $product?->id,
                        'campaign_id' => $campaign?->id,
                    ], $tenant);

                    $lead = $result['lead'];

                    if (($payload['notes'] ?? null) && $lead) {
                        $leads->addNote($lead, (string) $payload['notes']);
                    }
                } catch (ValidationException $e) {
                    Log::warning('webhook.crm.lead_rejected', [
                        'event_id' => $this->event->id,
                        'errors' => $e->errors(),
                    ]);
                }
            });

            $this->event->markProcessed();
        } catch (\Throwable $e) {
            Log::error('webhook.crm.process_failed', [
                'event_id' => $this->event->id,
                'error' => $e->getMessage(),
            ]);

            $this->event->markFailed();
        }
    }

    private function resolveCustomer(string $tenantId, array $payload, ?string $phone): ?Customer
    {
        $query = Customer::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId);

        if ($payload['customer_ref'] ?? null) {
            $customer = (clone $query)->where('id', $payload['customer_ref'])->first();

            if ($customer) {
                return $customer;
            }
        }

        if ($phone) {
            $customer = (clone $query)->where('phone', $phone)->first();

            if ($customer) {
                return $customer;
            }
        }

        if ($payload['email'] ?? null) {
            $customer = (clone $query)->where('email', $payload['email'])->first();

            if ($customer) {
                return $customer;
            }
        }

        return Customer::create([
            'tenant_id' => $tenantId,
            'name' => $payload['name'] ?? null,
            'phone' => $phone,
            'email' => $payload['email'] ?? null,
            'source' => 'crm',
            'consent_marketing' => (bool) ($payload['consent_marketing'] ?? ($payload['consent'] ?? false)),
            'consent_at' => ($payload['consent_marketing'] ?? ($payload['consent'] ?? false)) ? now() : null,
        ]);
    }
}