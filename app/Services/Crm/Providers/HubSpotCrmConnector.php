<?php

namespace App\Services\Crm\Providers;

use App\Models\Tenant;
use App\Services\Crm\Contracts\CrmConnector;
use App\Services\Crm\Exceptions\CrmConnectorException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Driver specifik HubSpot (§78, Sprint 13) — sinkronisasi deal + contact
 * via CRM API v3 (private app token). Payload masuk diharapkan baku dari
 * CrmEventFactory; dedup deal memakai custom property `tata_lead_id`
 * (konfigurasi crm.hubspot.deal_ref_property) yang wajib dibuat di akun.
 *
 * Event quotation.* diabaikan di HubSpot (tidak punya padanan satu-ke-satu);
 * cukup dicatat di delivery log sebagai sent.
 */
class HubSpotCrmConnector implements CrmConnector
{
    public function sync(Tenant $tenant, string $event, array $payload): array
    {
        if (str_starts_with($event, 'quotation.')) {
            Log::info('crm.connector.hubspot.ignored', [
                'tenant_id' => $tenant->id,
                'event' => $event,
            ]);

            return [
                'endpoint' => config('crm.hubspot.base_url', 'https://api.hubapi.com/crm/v3'),
                'http_status' => 200,
            ];
        }

        $contactId = $this->upsertContact($payload);
        $dealId = $this->upsertDeal($payload, $event);
        $this->associateDealContact($dealId, $contactId);

        return [
            'endpoint' => config('crm.hubspot.base_url', 'https://api.hubapi.com/crm/v3'),
            'http_status' => 200,
        ];
    }

    private function upsertContact(array $payload): string
    {
        $customer = $payload['customer'] ?? null;
        $email = is_array($customer) ? (string) ($customer['email'] ?? '') : '';
        $name = is_array($customer) ? (string) ($customer['name'] ?? '') : '';
        $phone = is_array($customer) ? (string) ($customer['phone'] ?? '') : '';

        if ($email !== '') {
            $hits = $this->search('contacts', [
                ['propertyName' => 'email', 'operator' => 'EQ', 'value' => $email],
            ]);

            if ($hits !== []) {
                return (string) $hits[0]['id'];
            }
        }

        $properties = [];
        if ($email !== '') {
            $properties['email'] = $email;
        }
        if ($name !== '') {
            $parts = array_values(array_filter(explode(' ', $name, 2)));
            $properties['firstname'] = $parts[0] ?? $name;
            $properties['lastname'] = $parts[1] ?? '';
        }
        if ($phone !== '') {
            $properties['phone'] = $phone;
        }

        $response = $this->request('POST', '/objects/contacts', ['properties' => $properties]);

        return (string) ($response->json('id') ?? '');
    }

    private function upsertDeal(array $payload, string $event): string
    {
        $refProperty = (string) config('crm.hubspot.deal_ref_property', 'tata_lead_id');
        $leadId = (string) ($payload['lead_id'] ?? '');
        $customer = $payload['customer'] ?? null;
        $product = $payload['product'] ?? null;

        $properties = [
            $refProperty => $leadId,
            'dealname' => $this->dealName($payload),
            'amount' => (string) ($payload['estimated_value'] ?? $payload['total'] ?? ''),
        ];

        $pipelineId = config('crm.hubspot.pipeline_id');
        if ($pipelineId) {
            $properties['pipeline'] = (string) $pipelineId;
        }

        $stageIds = (array) config('crm.hubspot.stage_ids', []);
        if (in_array($event, ['deal.won', 'deal.lost'], true)) {
            $key = $event === 'deal.won' ? 'won' : 'lost';
            if (! empty($stageIds[$key])) {
                $properties['dealstage'] = (string) $stageIds[$key];
            }
            $properties['hs_lead_status'] = $event === 'deal.won' ? 'CLOSED_WON' : 'CLOSED_LOST';
        } elseif (! empty($stageIds['new'])) {
            $properties['dealstage'] = (string) $stageIds['new'];
        }

        if (is_array($product) && isset($product['name'])) {
            $properties['dealname'] = (string) $customer['name']
                .' — '.(string) $product['name'];
        }

        if ($leadId !== '') {
            $hits = $this->search('deals', [
                ['propertyName' => $refProperty, 'operator' => 'EQ', 'value' => $leadId],
            ]);

            if ($hits !== []) {
                $dealId = (string) $hits[0]['id'];

                unset($properties[$refProperty]);
                $this->request('PATCH', '/objects/deals/'.$dealId, ['properties' => $properties]);

                return $dealId;
            }
        }

        $response = $this->request('POST', '/objects/deals', ['properties' => $properties]);

        return (string) ($response->json('id') ?? '');
    }

    private function dealName(array $payload): string
    {
        $customer = $payload['customer'] ?? null;
        $product = $payload['product'] ?? null;
        $name = is_array($customer) ? (string) ($customer['name'] ?? '') : '';

        if ($name === '' && is_array($customer) && isset($customer['phone'])) {
            $name = (string) $customer['phone'];
        }

        if (is_array($product) && isset($product['name'])) {
            return $name !== '' ? $name.' — '.(string) $product['name'] : (string) $product['name'];
        }

        return $name !== '' ? $name : 'TATA Deal';
    }

    private function associateDealContact(string $dealId, string $contactId): void
    {
        if ($dealId === '' || $contactId === '') {
            return;
        }

        $this->request('PUT', "/objects/deals/{$dealId}/associations/contacts/{$contactId}/4");
    }

    /**
     * @param  array<int, array<string, string>>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function search(string $object, array $filters): array
    {
        $response = $this->request('POST', '/objects/'.$object.'/search', [
            'filterGroups' => [['filters' => $filters]],
            'limit' => 1,
        ]);

        return (array) ($response->json('results') ?? []);
    }

    private function request(string $method, string $path, array $json = []): Response
    {
        $curlOptions = [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4];
        $caFile = (string) config('llm.ca_file', '');
        if ($caFile !== '' && is_file($caFile)) {
            $curlOptions[CURLOPT_CAINFO] = $caFile;
        }

        $url = rtrim((string) config('crm.hubspot.base_url'), '/').$path;

        try {
            $http = Http::asJson()
                ->withToken((string) config('crm.hubspot.api_key'))
                ->withOptions(['curl' => $curlOptions])
                ->timeout((int) config('crm.hubspot.timeout', 15))
                ->connectTimeout((int) config('crm.hubspot.timeout', 15));

            $response = match ($method) {
                'POST' => $http->post($url, $json),
                'PATCH' => $http->patch($url, $json),
                'PUT' => $http->put($url, $json),
                default => throw new \RuntimeException("Method {$method} tidak didukung."),
            };

            if (! $response->successful()) {
                throw new CrmConnectorException('HubSpot '.$method.' '.$path.' → HTTP '.$response->status(), $response->status());
            }

            return $response;
        } catch (ConnectionException $e) {
            throw new CrmConnectorException('HubSpot tidak dapat dijangkau (timeout/network).', null, $e);
        } catch (Throwable $e) {
            if ($e instanceof CrmConnectorException) {
                throw $e;
            }

            throw new CrmConnectorException('HubSpot gagal: '.$e->getMessage(), null, $e);
        }
    }
}
