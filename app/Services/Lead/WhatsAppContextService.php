<?php

namespace App\Services\Lead;

use App\Models\CalculatorSession;
use App\Models\Product;
use App\Models\Tenant;

class WhatsAppContextService
{
    /**
     * Bangun CTA WhatsApp kontekstual (§24): nomor tenant + pesan yang
     * menyertakan nama customer, produk, dan hasil kalkulator bila ada.
     *
     * @return array{url: string, message: string}
     */
    public function context(
        Tenant $tenant,
        ?string $customerName = null,
        ?Product $product = null,
        ?CalculatorSession $session = null,
        ?string $source = 'landing'
    ): array {
        $message = $this->message($customerName, $product, $session, $source);

        $phone = $tenant->settings['whatsapp_phone']
            ?? config('tata.whatsapp.default_phone');

        $url = config('tata.whatsapp.base_url').$phone.'?text='.rawurlencode($message);

        return [
            'url' => $url,
            'message' => $message,
        ];
    }

    private function message(?string $customerName, ?Product $product, ?CalculatorSession $session, string $source): string
    {
        $parts = [];

        if ($customerName) {
            $parts[] = 'Halo, saya '.$customerName;
        } else {
            $parts[] = 'Halo, saya ingin bertanya';
        }

        if ($product) {
            $parts[] = 'saya tertarik dengan '.$product->name;
        } else {
            $parts[] = 'saya tertarik dengan produk Anda';
        }

        if ($session && ! empty($session->output_data)) {
            $outputs = $session->output_data;

            if (isset($outputs['monthly_installment'])) {
                $parts[] = 'hasil kalkulasi: cicilan ±Rp'.number_format((float) $outputs['monthly_installment'], 0, ',', '.').'/bulan';
            } elseif (isset($outputs['total_payment'])) {
                $parts[] = 'hasil kalkulasi: total ±Rp'.number_format((float) $outputs['total_payment'], 0, ',', '.').'/bulan';
            } else {
                $parts[] = 'hasil kalkulasi bisa dihitungkan';
            }
        }

        $parts[] = 'bisa minta informasi lebih lanjut?';

        return implode(', ', $parts).'.';
    }
}
