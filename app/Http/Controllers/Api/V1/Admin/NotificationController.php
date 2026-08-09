<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Notification\SendNotificationRequest;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\Notification\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = Notification::query()
            ->when($user->role === 'sales', fn ($q) => $q->where('user_id', $user->id))
            ->when($request->filled('user_id') && $user->role !== 'sales', fn ($q) => $q->where('user_id', $request->string('user_id')))
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->string('channel')))
            ->orderByDesc('created_at')
            ->paginate(min($request->integer('per_page', 20), 100));

        return ApiResponse::paginated($notifications, NotificationResource::class);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        $user = $request->user();

        if ($notification->user_id !== $user->id && $user->role === 'sales') {
            return ApiResponse::error('FORBIDDEN', 'Bukan notifikasi milik Anda.', 403);
        }

        $notification->forceFill(['read_at' => now()])->save();

        return ApiResponse::success(new NotificationResource($notification->fresh()));
    }

    /**
     * Broadcast notifikasi owner/manager ke satu atau banyak user
     * via channel dashboard/email/whatsapp/webhook.
     */
    public function send(SendNotificationRequest $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        $data = $request->validated();

        $created = [];

        foreach ($data['user_ids'] as $userId) {
            $created[] = $this->service->notify(
                $tenant->id,
                $userId,
                $data['type'] ?? 'admin_message',
                $data['title'],
                $data['body'] ?? null,
                $data['data'] ?? [],
                $data['channel'] ?? 'dashboard',
            );
        }

        return ApiResponse::created(NotificationResource::collection($created));
    }
}
