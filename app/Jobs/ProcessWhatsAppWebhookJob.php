<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Services\Conversation\ConversationService;
use App\Services\Lead\LeadService;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\PhoneNormalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Proses webhook WA masuk: resolve/create customer → conversation →
 * lead (hanya bila consent ada) → pipeline lead penuh (§91: jangan
 * asumsikan consent; tanpa consent, percakapan tetap tercatat) →
 * balas customer lewat turn AI yang sama dengan channel webchat, dan
 * kirim balasannya kembali via WhatsAppService (outbox §25 Sprint 12).
 */
class ProcessWhatsAppWebhookJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public WebhookEvent $event) {}

    public function handle(LeadService $leads, ConversationService $conversations, WhatsAppService $whatsapp): void
    {
        try {
            $tenant = null;
            $customer = null;
            $conversation = null;
            $message = '';

            DB::transaction(function () use ($leads, &$tenant, &$customer, &$conversation, &$message) {
                $tenantId = $this->event->tenant_id;

                if (! $tenantId) {
                    throw new \RuntimeException('WebhookEvent tanpa tenant.');
                }

                $tenant = Tenant::query()->find($tenantId);

                if (! $tenant) {
                    throw new \RuntimeException("Tenant {$tenantId} tidak ditemukan.");
                }

                $payload = $this->event->payload;

                $phone = PhoneNormalizer::normalize((string) $payload['phone']);

                $customer = Customer::query()
                    ->withoutGlobalScope('tenant')
                    ->where('tenant_id', $tenantId)
                    ->where('phone', $phone)
                    ->first();

                if (! $customer) {
                    $customer = Customer::create([
                        'tenant_id' => $tenantId,
                        'name' => $payload['name'] ?? null,
                        'phone' => $phone,
                        'source' => 'whatsapp',
                        'consent_marketing' => (bool) ($payload['consent_marketing'] ?? false),
                        'consent_at' => ($payload['consent_marketing'] ?? false) ? now() : null,
                    ]);
                }

                $lead = $this->resolveOrCreateLead($leads, $tenant, $customer, $payload);

                $conversation = $this->findOrCreateConversation($tenantId, $customer, $lead?->id);

                $message = (string) $payload['message'];

                ConversationMessage::create([
                    'tenant_id' => $tenantId,
                    'conversation_id' => $conversation->id,
                    'sender_type' => ConversationMessage::SENDER_CUSTOMER,
                    'content' => $message,
                    'metadata' => [
                        'provider_event_id' => $this->event->provider_event_id,
                        'provider' => $this->event->provider,
                    ],
                ]);

                $conversation->forceFill([
                    'status' => Conversation::STATUS_AI_ACTIVE,
                    'updated_at' => now(),
                ])->save();

                if ($lead) {
                    $lead->forceFill(['last_activity_at' => now()])->save();
                }
            });

            app()->instance('currentTenant', $tenant);

            $this->respond($conversations, $whatsapp, $tenant, $customer, $conversation, $message);

            $this->event->markProcessed();
        } catch (\Throwable $e) {
            Log::error('webhook.whatsapp.process_failed', [
                'event_id' => $this->event->id,
                'error' => $e->getMessage(),
            ]);

            $this->event->markFailed();
        }
    }

    /**
     * Turn AI (intent → agent → jawaban disimpan) lalu kirim balasan ke
     * customer lewat jalur keluar tunggal. Gagal → jawaban fallback tetap
     * terkirim; error di sini tidak menggagalkan pemrosesan inbound.
     */
    private function respond(
        ConversationService $conversations,
        WhatsAppService $whatsapp,
        Tenant $tenant,
        Customer $customer,
        Conversation $conversation,
        string $message,
    ): void {
        try {
            $result = $conversations->turn($conversation, $tenant, $message);

            $reply = (string) ($result['reply'] ?? '');

            if ($reply === '') {
                return;
            }

            $context = [
                'conversation_id' => $conversation->id,
                'intent' => $result['intent'] ?? null,
                'source' => 'ai_reply',
            ];

            $lead = $conversation->lead_id
                ? Lead::query()->withoutGlobalScope('tenant')->find($conversation->lead_id)
                : null;

            if ($lead) {
                $whatsapp->send($lead, $reply, context: $context);
            } else {
                $whatsapp->sendToCustomer($customer, $tenant, $reply, $context);
            }
        } catch (\Throwable $e) {
            Log::warning('webhook.whatsapp.ai_reply_failed', [
                'event_id' => $this->event->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Lead existing → pakai; belum ada dan consent dikonfirmasi →
     * jalankan pipeline lead penuh; tanpa consent → null (percakapan tetap dicatat).
     */
    private function resolveOrCreateLead(LeadService $leads, Tenant $tenant, Customer $customer, array $payload)
    {
        $existing = $customer->leads()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->latest('created_at')
            ->first();

        if ($existing) {
            return $existing;
        }

        if (! ($payload['consent_marketing'] ?? false)) {
            return null;
        }

        $result = $leads->createFromForm([
            'customer' => [
                'name' => $payload['name'] ?? $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email ?? null,
            ],
            'source' => 'whatsapp',
            'consent_marketing' => true,
            'provider_event_id' => $this->event->provider_event_id,
        ], $tenant);

        return $result['lead'];
    }

    private function findOrCreateConversation(string $tenantId, Customer $customer, ?string $leadId): Conversation
    {
        $conversation = Conversation::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customer->id)
            ->where('channel', 'whatsapp')
            ->latest('updated_at')
            ->first();

        if (! $conversation) {
            $conversation = Conversation::create([
                'tenant_id' => $tenantId,
                'lead_id' => $leadId,
                'customer_id' => $customer->id,
                'channel' => 'whatsapp',
                'status' => Conversation::STATUS_AI_ACTIVE,
                'context' => [],
            ]);
        }

        return $conversation;
    }
}
