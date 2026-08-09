<?php

namespace App\Agents\Exceptions;

use RuntimeException;

class LLMException extends RuntimeException
{
    public static function unsupportedProvider(string $provider): self
    {
        return new self("LLM_PROVIDER '{$provider}' tidak dikenali. Pilih: anthropic, openai, gemini.");
    }

    public static function missingApiKey(string $provider): self
    {
        return new self("API key untuk LLM provider '{$provider}' belum di-set di .env.");
    }
}
