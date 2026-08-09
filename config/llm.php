<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Provider aktif (§65)
    |--------------------------------------------------------------------------
    |
    | Ganti LLM_PROVIDER = openai|anthropic|gemini tanpa menyentuh
    | kode agent — semua agent berbicara lewat interface LLMProvider.
    |
    */

    'provider' => env('LLM_PROVIDER', 'anthropic'),

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
        'timeout' => (int) env('LLM_TIMEOUT', 60),
        'max_tokens' => (int) env('LLM_MAX_TOKENS', 1024),
    ],

    'max_tool_iterations' => (int) env('LLM_MAX_TOOL_ITERATIONS', 6),
];
