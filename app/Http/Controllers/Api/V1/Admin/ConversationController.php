<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Conversation\HandoffConversationRequest;
use App\Http\Requests\Conversation\ReplyConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Conversation\ConversationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Conversation admin (Sales, §50):
 * - sales hanya melihat percakapan lead miliknya;
 * - owner/manager melihat semua percakapan tenant.
 * Balasan sales tercatat sebagai message sender `sales`
 * (pengiriman WA riil menunggu integrasi provider, Sprint 12).
 */
class ConversationController extends Controller
{
    public function __construct(private readonly ConversationService $conversations) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $conversations = Conversation::query()
            ->with(['customer', 'lead', 'lastMessage'])
            ->withCount('messages')
            ->when($user->role === 'sales', function ($query) use ($user) {
                $query->where(function ($q) use ($user) {
                    $q->whereHas('lead', fn ($l) => $l->withoutGlobalScope('tenant')->where('assigned_to', $user->id))
                        ->orWhere('assigned_to', $user->id);
                });
            })
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->string('customer_id')))
            ->when($request->filled('lead_id'), fn ($q) => $q->where('lead_id', $request->string('lead_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->string('channel')))
            ->orderByDesc('updated_at')
            ->paginate(min($request->integer('per_page', 20), 100));

        return ApiResponse::paginated($conversations, ConversationResource::class);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        if (! $this->canAccess($request->user(), $conversation)) {
            return ApiResponse::error('FORBIDDEN', 'Bukan percakapan milik Anda.', 403);
        }

        $messages = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn (ConversationMessage $message) => [
                'id' => $message->id,
                'sender_type' => $message->sender_type,
                'sender_id' => $message->sender_id,
                'sender_name' => $message->sender?->name,
                'content' => $message->content,
                'intent' => $message->intent,
                'metadata' => $message->metadata,
                'created_at' => $message->created_at?->toIso8601String(),
            ]);

        return ApiResponse::success($messages);
    }

    public function reply(ReplyConversationRequest $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();

        if (! $this->canAccess($user, $conversation)) {
            return ApiResponse::error('FORBIDDEN', 'Bukan percakapan milik Anda.', 403);
        }

        $message = $conversation->messages()->create([
            'tenant_id' => $conversation->tenant_id,
            'sender_type' => ConversationMessage::SENDER_SALES,
            'sender_id' => $user->id,
            'content' => $request->string('content')->toString(),
            'intent' => null,
            'metadata' => [
                'status' => 'queued',
                'channel' => 'whatsapp',
            ],
        ]);

        $conversation->markHumanActive($user->id);

        return ApiResponse::created([
            'id' => $message->id,
            'sender_type' => $message->sender_type,
            'content' => $message->content,
            'created_at' => $message->created_at?->toIso8601String(),
        ]);
    }

    /**
     * Handoff (§6, API §3): sales/manager menyerahkan ke manusia
     * (WAITING_HUMAN) atau mengembalikan ke AI (AI_RESUMED).
     */
    public function handoff(HandoffConversationRequest $request, Conversation $conversation): JsonResponse
    {
        if (! $this->canAccess($request->user(), $conversation)) {
            return ApiResponse::error('FORBIDDEN', 'Bukan percakapan milik Anda.', 403);
        }

        $to = $request->string('to')->toString();

        if ($to === 'human') {
            $this->conversations->handoff($conversation, 'handoff manual oleh sales', 'admin');

            return ApiResponse::success([
                'conversation_id' => $conversation->id,
                'status' => Conversation::STATUS_WAITING_HUMAN,
            ]);
        }

        $conversation->forceFill([
            'status' => Conversation::STATUS_AI_RESUMED,
            'assigned_to' => null,
            'updated_at' => now(),
        ])->save();

        $conversation->messages()->create([
            'tenant_id' => $conversation->tenant_id,
            'sender_type' => ConversationMessage::SENDER_SYSTEM,
            'content' => 'Percakapan dikembalikan ke asisten AI.',
            'intent' => 'ai_resumed',
            'metadata' => ['source' => 'admin'],
        ]);

        return ApiResponse::success([
            'conversation_id' => $conversation->id,
            'status' => Conversation::STATUS_AI_RESUMED,
        ]);
    }

    private function canAccess(?object $user, Conversation $conversation): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->role !== 'sales') {
            return true;
        }

        if ($conversation->assigned_to === $user->id) {
            return true;
        }

        return $conversation->lead?->assigned_to === $user->id;
    }
}
