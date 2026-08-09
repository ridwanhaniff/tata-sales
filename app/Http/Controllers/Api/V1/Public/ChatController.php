<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Services\Conversation\ConversationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ChatController extends Controller
{
    public function __construct(private readonly ConversationService $service) {}

    /**
     * Endpoint chat publik (§119): satu turn — simpan pesan, jawab via AI
     * agent, atau serahkan ke manusia bila AI tidak yakin / keluhan.
     */
    public function message(SendMessageRequest $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $result = $this->service->chat(
            customerPhone: $request->validated('customer_phone'),
            message: $request->validated('message'),
            conversationId: $request->validated('conversation_id'),
            tenant: $tenant,
        );

        return ApiResponse::success($result, status: 200);
    }
}
