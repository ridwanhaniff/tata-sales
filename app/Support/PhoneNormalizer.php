<?php

namespace App\Support;

use InvalidArgumentException;

class PhoneNormalizer
{
    /**
     * Normalisasi nomor HP Indonesia ke format 62xxxxxxxxxx (§81).
     *
     * @throws InvalidArgumentException bila format tidak dikenal
     */
    public static function normalize(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if ($digits === null || $digits === '') {
            throw new InvalidArgumentException('Nomor HP tidak valid.');
        }

        if (str_starts_with($digits, '62')) {
            $normalized = $digits;
        } elseif (str_starts_with($digits, '0')) {
            $normalized = '62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '8')) {
            $normalized = '62'.$digits;
        } else {
            throw new InvalidArgumentException('Nomor HP tidak valid.');
        }

        if (strlen($normalized) < 10 || strlen($normalized) > 15) {
            throw new InvalidArgumentException('Nomor HP tidak valid.');
        }

        return $normalized;
    }
}
