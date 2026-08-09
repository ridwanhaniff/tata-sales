<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCrmWebhookJob;
use App\Jobs\ProcessWhatsAppWebhookJob;
use App\Models\Tenant;
use App\Models\WebhookEvent;
use App\Support\ApiResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Webhook masuk (§79-80) — seluruh provider wajib:
 * 1. Verifikasi signature (HMAC header X-TataSales-Signature, secret per tenant).
 * 2. Validasi schema payload.
 * 3. Idempotency via `webhook_events` UNIQUE(provider, provider_event_id) —
 *    event yang sama dikirim ulang → 200 `duplicate`, tanpa proses ulang.
 * 4. Simpan event dulu (received), baru proses via queue job (retry-safe).
 */
class WebhookController extends Controller
{
    public function whatsapp(Request $request): JsonResponse
    {
        return $this->ingest('whatsapp', $request, [
            'provider_event_id' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'message' => ['required', 'string'],
        ]);
    }

    public function payment(Request $request): JsonResponse
    {
        return $this->ingest('payment', $request, [
            'provider_event_id' => ['required', 'string', 'max:255'],
        ]);
    }

    public function crm(Request $request): JsonResponse
    {
        return $this->ingest('crm', $request, [
            'provider_event_id' => ['required', 'string', 'max:255'],
        ]);
    }

    private function ingest(string $provider, Request $request, array $rules): JsonResponse
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

        if (! $this->signatureValid($request, (string) $secret)) {
            return ApiResponse::error('FORBIDDEN', 'Signature tidak cocok.', 401);
        }

        $payload = $request->input();

        $errors = $this->validatePayload($payload, $rules);

        if ($errors !== []) {
            return ApiResponse::error('VALIDATION_FAILED', 'Payload tidak valid.', 422, $errors);
        }

        $providerEventId = (string) $payload['provider_event_id'];

        $existing = WebhookEvent::query()
            ->withoutGlobalScope('tenant')
            ->where('provider', $provider)
            ->where('provider_event_id', $providerEventId)
            ->first();

        if ($existing) {
            return ApiResponse::success(['status' => 'duplicate', 'event_id' => $existing->id]);
        }

        try {
            $event = WebhookEvent::create([
                'tenant_id' => $tenant->id,
                'provider' => $provider,
                'provider_event_id' => $providerEventId,
                'payload' => $payload,
                'status' => WebhookEvent::STATUS_RECEIVED,
            ]);
        } catch (UniqueConstraintViolationException) {
            // balapan dua request dengan event yang sama — pihak kedua dianggap duplikat (§80)
            return ApiResponse::success(['status' => 'duplicate', 'event_id' => null]);
        }

        if ($provider === 'whatsapp') {
            dispatch(new ProcessWhatsAppWebhookJob($event));
        } elseif ($provider === 'crm') {
            dispatch(new ProcessCrmWebhookJob($event));
        } else {
            // MVP: payment hanya tercatat; proses penuh menunggu integrasi.
            $event->markProcessed();
        }

        return ApiResponse::success(['status' => 'received', 'event_id' => $event->id]);
    }

    private function signatureValid(Request $request, string $secret): bool
    {
        $provided = $request->header('X-TataSales-Signature');

        if (! $provided) {
            return false;
        }

        $expected = hash_hmac('sha256', (string) $request->getContent(), $secret);

        return hash_equals($expected, (string) $provided);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload, array $rules): array
    {
        $validator = Validator::make($payload, $rules);

        if ($validator->fails()) {
            return $validator->errors()->toArray();
        }

        return [];
    }
}
