<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Konektor CRM (§78, Sprint 13)
    |--------------------------------------------------------------------------
    |
    | Driver konektor event CRM keluar. Semua driver memakai payload baku
    | dari CrmEventFactory (schema kanonik) dan mencatat hasilnya di
    | `crm_deliveries` (delivery log).
    |
    | - echo    : dev/test, tidak memanggil jaringan, hanya mencatat.
    | - http    : generic webhook HMAC ke tenants.settings.webhook.url
    |             (envelope {event, tenant_id, data, sent_at}).
    | - hubspot : HubSpot CRM API (deals + contacts), token private app.
    |
    */

    'driver' => env('CRM_DRIVER', 'echo'),

    'http' => [
        'timeout' => (int) env('CRM_HTTP_TIMEOUT', 10),
    ],

    'hubspot' => [
        'api_key' => env('CRM_HUBSPOT_API_KEY', ''),
        'base_url' => env('CRM_HUBSPOT_BASE_URL', 'https://api.hubapi.com/crm/v3'),
        // Pipeline/dealstage default; kosong = abaikan (HubSpot pakai default akun).
        'pipeline_id' => env('CRM_HUBSPOT_PIPELINE_ID'),
        'stage_ids' => [
            'won' => env('CRM_HUBSPOT_STAGE_WON'),
            'lost' => env('CRM_HUBSPOT_STAGE_LOST'),
            'new' => env('CRM_HUBSPOT_STAGE_NEW'),
        ],
        // Custom property di Deal untuk dedup (lead.id). Wajib dibuat di HubSpot.
        'deal_ref_property' => env('CRM_HUBSPOT_DEAL_REF_PROPERTY', 'tata_lead_id'),
        'timeout' => (int) env('CRM_HUBSPOT_TIMEOUT', 15),
    ],
];
