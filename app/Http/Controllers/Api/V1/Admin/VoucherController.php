<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promotion\GenerateVoucherRequest;
use App\Http\Resources\VoucherResource;
use App\Models\Promotion;
use App\Models\Voucher;
use App\Services\Promotion\VoucherService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoucherController extends Controller
{
    public function __construct(private readonly VoucherService $service) {}

    public function index(Request $request): JsonResponse
    {
        $vouchers = Voucher::query()
            ->with('promotion')
            ->when($request->filled('promotion_id'), fn ($q) => $q->where('promotion_id', $request->string('promotion_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $q->where('code', 'ilike', '%'.$request->string('search').'%');
            })
            ->orderByDesc('created_at')
            ->paginate(min($request->integer('per_page', 20), 100));

        return ApiResponse::paginated($vouchers, VoucherResource::class);
    }

    public function generate(GenerateVoucherRequest $request, Promotion $promotion): JsonResponse
    {
        $voucher = $this->service->generate(
            $promotion,
            (int) $request->integer('count'),
            (string) $request->string('prefix', 'TATA')
        );

        return ApiResponse::created(
            new VoucherResource($voucher),
            ['vouchers' => Voucher::query()->where('promotion_id', $promotion->id)->pluck('code')]
        );
    }
}
