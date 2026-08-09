<?php

namespace App\Services\WhatsApp\Contracts;

use App\Models\WhatsappMessage;

interface WhatsAppProvider
{
    /**
     * Kirim pesan via provider.
     *
     * @return array{provider_message_id: string, status: string}
     *
     * @throws \RuntimeException saat provider menolak/gagal
     */
    public function send(WhatsappMessage $message): array;
}
