<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider aktif (§65)
    |--------------------------------------------------------------------------
    |
    | Ganti LLM_PROVIDER = openai|anthropic tanpa menyentuh kode agent —
    | semua agent berbicara lewat interface LLMProvider.
    |
    | `openai` adalah adapter OpenAI-compatible: bisa diarahkan ke Gemini
    | (default), Groq, GitHub Models, atau OpenRouter hanya dengan mengganti
    | base_url + model. Saat kena rate limit (429) pada provider utama,
    | request otomatis dicoba ulang sekali ke `fallback` (Groq).
    |
    */

    'provider' => env('LLM_PROVIDER', 'openai'),

    'openai' => [
        'api_key' => env('LLM_API_KEY', ''),
        'model' => env('LLM_MODEL', 'gemini-3-flash'),
        'base_url' => env('LLM_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/openai/'),
        'timeout' => (int) env('LLM_TIMEOUT', 60),
        'max_tokens' => (int) env('LLM_MAX_TOKENS', 1024),
        // OpenCode Zen: max_tokens wajib dibungkus model_parameters.params.
        'use_model_parameters' => (bool) env('LLM_USE_MODEL_PARAMETERS', false),
    ],

    // Fallback otomatis saat 429 (rate limit) — default: Groq.
    'fallback' => [
        'enabled' => (bool) env('LLM_FALLBACK_ENABLED', true),
        'api_key' => env('LLM_FALLBACK_API_KEY', ''),
        'model' => env('LLM_FALLBACK_MODEL', 'llama-3.3-70b-versatile'),
        'base_url' => env('LLM_FALLBACK_BASE_URL', 'https://api.groq.com/openai/v1'),
    ],

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
        'timeout' => (int) env('LLM_TIMEOUT', 60),
        'max_tokens' => (int) env('LLM_MAX_TOKENS', 1024),
    ],

    'max_tool_iterations' => (int) env('LLM_MAX_TOOL_ITERATIONS', 6),

    // Bundle CA untuk cURL. Windows/laragon biasanya tanpa CA root sehingga
    // SSL verify gagal (cURL error 60); unduh dari https://curl.se/ca/cacert.pem.
    // Jika file tidak ada, cURL memakai default sistem.
    'ca_file' => env('LLM_CA_FILE', storage_path('certs/cacert.pem')),
];
