<?php

namespace App\Providers;

use App\Agents\Contracts\LLMProvider;
use App\Agents\Exceptions\LLMException;
use App\Agents\Providers\AnthropicProvider;
use Illuminate\Support\ServiceProvider;

class LLMServiceProvider extends ServiceProvider
{
    /**
     * Bind interface LLMProvider ke adapter aktif (§65). Ganti provider
     * = ubah config/llm.php — tidak ada kode agent yang perlu diubah.
     */
    public function register(): void
    {
        $this->app->singleton(LLMProvider::class, function () {
            return match (config('llm.provider')) {
                'anthropic', 'anthropic_beta' => new AnthropicProvider(
                    apiKey: config('llm.anthropic.api_key', ''),
                    model: config('llm.anthropic.model', ''),
                    baseUrl: config('llm.anthropic.base_url', ''),
                    timeout: config('llm.anthropic.timeout', 60),
                ),
                default => throw LLMException::unsupportedProvider((string) config('llm.provider')),
            };
        });
    }
}
