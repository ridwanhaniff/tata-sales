<?php

namespace App\Services\WhatsApp;

use App\Models\Customer;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\Quotation;
use App\Models\Tenant;
use App\Models\WhatsappMessage;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use App\Services\WhatsApp\Providers\EchoWhatsAppProvider;
use App\Services\WhatsApp\Providers\MetaWhatsAppProvider;
use Illuminate\Support\Facades\Log;

/**
 * Outbox WhatsApp Business API (§25 Sprint 12) — semua pesan keluar lewat
 * satu jalur: dicatat di whatsapp_messages lalu dikirim via provider aktif.
 */
class WhatsAppService
{
    private ?WhatsAppProvider $provider = null;

    public function provider(): WhatsAppProvider
    {
        if ($this->provider !== null) {
            return $this->provider;
        }

        $driver = (string) config('tata.whatsapp.driver', 'echo');

        $instance = match ($driver) {
            'meta' => new MetaWhatsAppProvider,
            default => new EchoWhatsAppProvider,
        };

        return $this->provider = $instance;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function send(
        Lead $lead,
        string $message,
        ?Followup $followup = null,
        ?Quotation $quotation = null,
        array $context = [],
    ): WhatsappMessage {
        $phone = $lead->customer?->phone;

        if (! $phone) {
            throw new \RuntimeException('Nomor WhatsApp customer tidak tersedia.');
        }

        return $this->deliver([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'followup_id' => $followup?->id,
            'quotation_id' => $quotation?->id,
            'to_phone' => (string) $phone,
            'message' => $message,
            'payload' => $context === [] ? null : $context,
        ]);
    }

    /**
     * Balasan AI chat untuk customer yang belum punya lead (mis. WhatsApp
     * tanpa consent) — tetap tercatat di whatsapp_messages, lead_id null.
     *
     * @param  array<string, mixed>  $context
     */
    public function sendToCustomer(
        Customer $customer,
        Tenant $tenant,
        string $message,
        array $context = [],
    ): WhatsappMessage {
        $phone = $customer->phone;

        if (! $phone) {
            throw new \RuntimeException('Nomor WhatsApp customer tidak tersedia.');
        }

        return $this->deliver([
            'tenant_id' => $tenant->id,
            'to_phone' => (string) $phone,
            'message' => $message,
            'payload' => $context === [] ? null : $context,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function deliver(array $data): WhatsappMessage
    {
        $data['message'] = mb_substr((string) $data['message'], 0, 4000);

        $record = WhatsappMessage::create($data + [
            'provider' => (string) config('tata.whatsapp.driver', 'echo'),
            'status' => 'queued',
        ]);

        try {
            $result = $this->provider()->send($record);

            $record->forceFill([
                'status' => $result['status'] ?? 'sent',
                'provider_message_id' => $result['provider_message_id'] ?? null,
                'sent_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('whatsapp.send_failed', [
                'id' => $record->id,
                'lead_id' => $record->lead_id,
                'error' => $e->getMessage(),
            ]);

            $record->forceFill([
                'status' => 'failed',
                'provider_error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();
        }

        return $record;
    }
}
