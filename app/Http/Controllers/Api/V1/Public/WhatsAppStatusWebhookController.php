<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\WhatsappMessage;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Webhook status WhatsApp Business API (Sprint 12): provider (Meta)
 * memberi tahu pesan sudah sent/delivered/read/failed via provider_message_id.
 *
 * Verifikasi sama seperti webhook lain (§79): HMAC header
 * X-TataSales-Signature dengan secret webhook inbound tenant.
 */
class WhatsAppStatusWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $tenantId = $request->header('X-TataSales-Tenant');

        if (! $tenantId) {
            return ApiResponse::error('MISSING_TENANT', 'Header X-TataSales-Tenant wajib diisi.', 400);
        }

        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant) {
            return ApiResponse::error('UNKNOWN_TENANT', 'Tenant tidak dikenal.', 404);
        }

        $secret = $tenant->settings['webhook']['inbound_secret'] ?? null;

        if (! $secret) {
            return ApiResponse::error('WEBHOOK_NOT_CONFIGURED', 'Webhook inbound belum dikonfigurasi tenant.', 401);
        }

        $provided = $request->header('X-TataSales-Signature');
        $expected = hash_hmac('sha256', (string) $request->getContent(), (string) $secret);

        if (! $provided || ! hash_equals($expected, (string) $provided)) {
            return ApiResponse::error('FORBIDDEN', 'Signature tidak cocok.', 401);
        }

        $payload = $request->input();

        $validator = Validator::make($payload, [
            'provider_message_id' => ['required', 'string', 'max:255'],
            'status' => ['required', 'in:sent,delivered,read,failed'],
            'error' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('VALIDATION_FAILED', 'Payload tidak valid.', 422, $validator->errors()->toArray());
        }

        $message = WhatsappMessage::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider_message_id', $payload['provider_message_id'])
            ->first();

        if (! $message) {
            return ApiResponse::error('NOT_FOUND', 'Pesan WhatsApp tidak ditemukan.', 404);
        }

        $status = (string) $payload['status'];

        $message->forceFill([
            'status' => $status,
            'provider_error' => $payload['error'] ?? $message->provider_error,
            'delivered_at' => $status === 'delivered' ? now() : $message->delivered_at,
            'read_at' => $status === 'read' ? now() : $message->read_at,
        ])->save();

        return ApiResponse::success(['status' => $status, 'id' => $message->id]);
    }
}
