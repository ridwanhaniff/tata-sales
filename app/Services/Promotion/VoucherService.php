<?php

namespace App\Services\Promotion;

use App\Models\Customer;
use App\Models\Promotion;
use App\Models\Voucher;
use App\Models\VoucherUsage;
use App\Support\AuditLogger;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class VoucherService
{
    public const MAX_CODES_PER_BATCH = 200;

    /**
     * Generate kode voucher unik per tenant (§21).
     * Format kode: {PREFIX}-<4 karakter alfanumerik>, contoh TATA-A8F2.
     */
    public function generate(Promotion $promotion, int $count, string $prefix = 'TATA'): Promotion
    {
        if ($count < 1 || $count > self::MAX_CODES_PER_BATCH) {
            throw ValidationException::withMessages([
                'count' => ['count harus antara 1 dan '.self::MAX_CODES_PER_BATCH.'.'],
            ]);
        }

        $prefix = Str::upper((string) preg_replace('/[^A-Za-z0-9-]/', '', $prefix)) ?: 'TATA';
        $prefix = Str::limit($prefix, 20, '');

        return DB::transaction(function () use ($promotion, $count, $prefix) {
            $existing = Voucher::query()
                ->where('tenant_id', $promotion->tenant_id)
                ->pluck('code')
                ->flip();

            $codes = [];

            while (count($codes) < $count) {
                $code = $prefix.'-'.Str::upper(Str::random(4));

                if (isset($existing[$code]) || in_array($code, $codes, true)) {
                    continue;
                }

                $codes[] = $code;
            }

            foreach ($codes as $code) {
                Voucher::create([
                    'tenant_id' => $promotion->tenant_id,
                    'promotion_id' => $promotion->id,
                    'code' => $code,
                    'discount_type' => $promotion->discount_type,
                    'discount_value' => $promotion->discount_value,
                    'minimum_purchase' => $promotion->minimum_purchase,
                    'usage_limit' => $promotion->usage_limit,
                    'per_customer_limit' => 1,
                    'usage_count' => 0,
                    'expires_at' => $promotion->ends_at,
                    'status' => 'active',
                    'created_at' => now(),
                ]);
            }

            AuditLogger::log('voucher.generated', 'promotion', $promotion->id, [], [
                'count' => $count,
                'prefix' => $prefix,
                'codes' => $codes,
            ]);

            return $promotion->fresh();
        });
    }

    /**
     * Redeem kode voucher (§114).
     *
     * @param  array{name?: string, phone?: string}  $customerData
     */
    public function redeem(string $code, array $customerData = [], ?string $leadId = null): array
    {
        return DB::transaction(function () use ($code, $customerData, $leadId) {
            $voucher = Voucher::query()->where('code', $code)->lockForUpdate()->first();

            if (! $voucher) {
                throw ValidationException::withMessages([
                    'code' => ['Kode voucher tidak ditemukan.'],
                ]);
            }

            if ($voucher->status !== 'active') {
                throw ValidationException::withMessages([
                    'code' => ['Voucher tidak aktif.'],
                ]);
            }

            if ($voucher->expires_at && $voucher->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'code' => ['Voucher sudah kedaluwarsa.'],
                ]);
            }

            if ($voucher->usage_limit !== null && $voucher->usage_count >= $voucher->usage_limit) {
                throw ValidationException::withMessages([
                    'code' => ['Voucher sudah mencapai batas penggunaan.'],
                ]);
            }

            $customer = null;
            $phone = null;

            if (! empty($customerData['phone'])) {
                try {
                    $phone = PhoneNormalizer::normalize((string) $customerData['phone']);
                } catch (InvalidArgumentException $e) {
                    throw ValidationException::withMessages([
                        'customer.phone' => [$e->getMessage()],
                    ]);
                }

                $customer = Customer::query()->where('phone', $phone)->first();

                if (! $customer) {
                    $customer = Customer::create([
                        'tenant_id' => $voucher->tenant_id,
                        'name' => $customerData['name'] ?? null,
                        'phone' => $phone,
                        'source' => 'voucher',
                        'consent_marketing' => false,
                    ]);
                }

                $usage = VoucherUsage::query()
                    ->where('voucher_id', $voucher->id)
                    ->where('customer_id', $customer->id)
                    ->count();

                if ($usage >= $voucher->per_customer_limit) {
                    throw ValidationException::withMessages([
                        'code' => ['Voucher sudah dipakai customer yang sama.'],
                    ]);
                }
            }

            VoucherUsage::create([
                'tenant_id' => $voucher->tenant_id,
                'voucher_id' => $voucher->id,
                'customer_id' => $customer?->id,
                'lead_id' => $leadId,
                'used_at' => now(),
            ]);

            $voucher->increment('usage_count');

            return $voucher->fresh()->toArray();
        });
    }
}
