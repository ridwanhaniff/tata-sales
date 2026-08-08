<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lead scoring (§15)
    |--------------------------------------------------------------------------
    | Bobot default rule-based; tenant bisa override lewat
    | tenants.settings.scoring_weights.
    */

    'scoring' => [
        'weights' => [
            'has_email' => 5,
            'has_location' => 5,
            'has_product' => 10,
            'has_variant' => 5,
            'calculator_completed' => 20,
            'consent_marketing' => 5,
            'source_whatsapp' => 10,
            'source_chat' => 10,
            'source_form' => 5,
            'has_campaign' => 5,
        ],
        'max_score' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Lead pipeline default (§27)
    |--------------------------------------------------------------------------
    | Transisi valid status lead (06-lead-state-machine.md).
    */

    'pipeline' => [
        'transitions' => [
            'NEW' => ['CONTACTED', 'NURTURE', 'LOST'],
            'CONTACTED' => ['QUALIFIED', 'NURTURE', 'LOST'],
            'QUALIFIED' => ['PROPOSAL', 'LOST'],
            'PROPOSAL' => ['NEGOTIATION', 'LOST'],
            'NEGOTIATION' => ['WON', 'LOST', 'PROPOSAL'],
            'NURTURE' => ['CONTACTED', 'LOST'],
            'WON' => [],
            'LOST' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp CTA (§24)
    |--------------------------------------------------------------------------
    | Nomor WA default tenant dari tenants.settings.whatsapp_phone;
    | fallback di sini bila belum di-set.
    */

    'whatsapp' => [
        'default_phone' => env('WHATSAPP_DEFAULT_PHONE', '6280000000000'),
        'base_url' => 'https://wa.me/',
    ],
];
