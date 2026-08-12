<?php

namespace App\Services\Crm\Providers;

use App\Models\Tenant;
use App\Services\Crm\Contracts\CrmConnector;
use App\Services\Crm\Exceptions\CrmConnectorException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Driver generic (§77): POST ke `tenants.settings.webhook.url` dengan
 * envelope {event, tenant_id, data, sent_at} + HMAC-SHA256 header
 * X-TataSales-Signature (secret dari settings tenant). Retry & delivery
 * log ditangani DispatchCrmEventJob.
 */
class HttpCrmConnector implements CrmConnector
{
    public function sync(Tenant $tenant, string $event, array $payload): array
    {
        $settings = $tenant->settings['webhook'] ?? null;

        if (! is_array($settings) || empty($settings['url'])) {
            throw new CrmConnectorException('Webhook URL belum dikonfigurasi tenant.');
        }

        $url = (string) $settings['url'];
        $secret = (string) ($settings['secret'] ?? '');

        $body = json_encode([
            'event' => $event,
            'tenant_id' => $tenant->id,
            'data' => $payload,
            'sent_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $curlOptions = [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4];
        $caFile = (string) config('llm.ca_file', '');
        if ($caFile !== '' && is_file($caFile)) {
            $curlOptions[CURLOPT_CAINFO] = $caFile;
        }

        try {
            $response = Http::timeout((int) config('crm.http.timeout', 10))
                ->connectTimeout((int) config('crm.http.timeout', 10))
                ->withOptions(['curl' => $curlOptions])
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-TataSales-Signature' => hash_hmac('sha256', (string) $body, $secret),
                ])
                ->withBody((string) $body, 'application/json')
                ->post($url);

            if (! $response->successful()) {
                throw new CrmConnectorException('CRM HTTP '.$response->status(), $response->status());
            }

            return [
                'endpoint' => $url,
                'http_status' => $response->status(),
            ];
        } catch (ConnectionException $e) {
            throw new CrmConnectorException('CRM tidak dapat dijangkau (timeout/network).', null, $e);
        } catch (Throwable $e) {
            if ($e instanceof CrmConnectorException) {
                throw $e;
            }

            throw new CrmConnectorException('CRM HTTP gagal: '.$e->getMessage(), null, $e);
        }
    }
}
