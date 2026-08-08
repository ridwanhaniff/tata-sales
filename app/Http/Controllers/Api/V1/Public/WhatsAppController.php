<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lead\WhatsAppContextRequest;
use App\Models\CalculatorSession;
use App\Models\Product;
use App\Services\Lead\WhatsAppContextService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class WhatsAppController extends Controller
{
    public function __construct(private readonly WhatsAppContextService $service) {}

    /**
     * CTA WhatsApp kontekstual (§24): URL wa.me + pesan yang menyertakan
     * nama customer, produk, dan hasil kalkulator bila ada.
     */
    public function context(WhatsAppContextRequest $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $product = $request->filled('product_id')
            ? Product::query()->find($request->string('product_id')->toString())
            : null;

        $session = $request->filled('calculator_session_id')
            ? CalculatorSession::query()->find($request->string('calculator_session_id')->toString())
            : null;

        $context = $this->service->context(
            $tenant,
            customerName: $request->filled('customer_name') ? $request->string('customer_name')->toString() : null,
            product: $product,
            session: $session,
            source: $request->filled('source') ? $request->string('source')->toString() : 'landing',
        );

        return ApiResponse::success($context);
    }
}
