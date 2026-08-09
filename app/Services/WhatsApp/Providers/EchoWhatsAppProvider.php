<?php

namespace App\Services\WhatsApp\Providers;

use App\Models\WhatsappMessage;
use App\Services\WhatsApp\Contracts\WhatsAppProvider;
use Illuminate\Support\Str;

/**
 * Driver default (dev/test & belum konfigurasi provider riil): tidak
 * memanggil jaringan, selalu dianggap terkirim — untuk verifikasi alur
 * end-to-end tanpa akun WhatsApp Business.
 */
class EchoWhatsAppProvider implements WhatsAppProvider
{
    public function send(WhatsappMessage $message): array
    {
        return [
            'provider_message_id' => 'echo-'.Str::uuid(),
            'status' => 'sent',
        ];
    }
}
