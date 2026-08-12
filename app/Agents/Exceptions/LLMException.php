<?php

namespace App\Agents\Exceptions;

use RuntimeException;
use Throwable;

class LLMException extends RuntimeException
{
    private bool $rateLimited = false;

    public static function unsupportedProvider(string $provider): self
    {
        return new self("LLM_PROVIDER '{$provider}' tidak dikenali. Pilih: openai, anthropic.");
    }

    public static function missingApiKey(string $provider): self
    {
        return new self("API key untuk LLM provider '{$provider}' belum di-set di .env.");
    }

    /**
     * Bungkus exception HTTP dari panggilan LLM; menandai 429 sebagai
     * rate-limit agar adapter bisa fallback ke provider cadangan.
     */
    public static function fromHttp(Throwable $e, string $model): self
    {
        $response = method_exists($e, 'response')
            ? $e->response()
            : (isset($e->response) ? $e->response : null);

        $status = $response ? $response->status() : null;

        $exception = new self(
            "Panggilan LLM ({$model}) gagal".($status ? " — HTTP {$status}" : '').': '.$e->getMessage(),
            0,
            $e
        );

        if ($status === 429) {
            $exception->rateLimited = true;
        }

        return $exception;
    }

    public function rateLimited(): bool
    {
        return $this->rateLimited;
    }
}
