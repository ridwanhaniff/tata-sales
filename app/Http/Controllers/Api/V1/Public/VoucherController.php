<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Voucher\RedeemVoucherRequest;
use App\Services\Promotion\VoucherService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class VoucherController extends Controller
{
    public function __construct(private readonly VoucherService $service) {}

    public function redeem(RedeemVoucherRequest $request): JsonResponse
    {
        $voucher = $this->service->redeem(
            $request->string('code')->toString(),
            (array) $request->input('customer', []),
            $request->input('lead_id')
        );

        return ApiResponse::success([
            'code' => $voucher['code'],
            'discount_type' => $voucher['discount_type'],
            'discount_value' => (float) $voucher['discount_value'],
            'minimum_purchase' => $voucher['minimum_purchase'] !== null ? (float) $voucher['minimum_purchase'] : null,
            'usage_count' => $voucher['usage_count'],
            'message' => 'Voucher berhasil dipakai.',
        ]);
    }
}
