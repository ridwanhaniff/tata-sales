<?php

namespace App\Services\WhatsApp\Providers;

use App\Models\WhatsappMessage;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use Illuminate\Support\Facades\Http;

/**
 * Meta WhatsApp Cloud API (§25 Sprint 12).
 *
 * Konfigurasi (config/tata.php → whatsapp.meta):
 * - token: system user token
 * - phone_number_id: nomor bisnis pengirim
 * - graph_version: default v22.0
 *
 * Gagal mengirim → throw RuntimeException; WhatsAppService mencatatnya
 * sebagai status failed di whatsapp_messages.
 */
class MetaWhatsAppProvider implements WhatsAppProvider
{
    public function send(WhatsappMessage $message): array
    {
        $meta = config('tata.whatsapp.meta', []);

        if (empty($meta['token']) || empty($meta['phone_number_id'])) {
            throw new \RuntimeException('WhatsApp Business API belum dikonfigurasi (token / phone_number_id).');
        }

        $response = Http::acceptJson()
            ->timeout(15)
            ->withToken($meta['token'])
            ->post(
                sprintf('%s/%s/%s/messages', $meta['graph_base_url'] ?? 'https://graph.facebook.com', $meta['graph_version'] ?? 'v22.0', $meta['phone_number_id']),
                [
                    'messaging_product' => 'whatsapp',
                    'to' => $message->to_phone,
                    'type' => 'text',
                    'text' => ['preview_url' => true, 'body' => $message->message],
                ]
            );

        if ($response->failed() || empty($response->json('messages.0.id'))) {
            throw new \RuntimeException('Meta gagal mengirim pesan: '.($response->json('error.message') ?? $response->status()));
        }

        return [
            'provider_message_id' => (string) $response->json('messages.0.id'),
            'status' => 'sent',
        ];
    }
}
