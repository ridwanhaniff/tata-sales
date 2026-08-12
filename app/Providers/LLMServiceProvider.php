<?php

namespace App\Providers;

use App\Agents\Contracts\LLMProvider;
use App\Agents\Exceptions\LLMException;
use App\Agents\Providers\AnthropicProvider;
use App\Agents\Providers\OpenAICompatibleProvider;
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
                'openai', 'openai_compatible', 'gemini' => new OpenAICompatibleProvider(
                    apiKey: config('llm.openai.api_key', ''),
                    model: config('llm.openai.model', ''),
                    baseUrl: config('llm.openai.base_url', ''),
                    timeout: config('llm.openai.timeout', 60),
                    fallbackApiKey: config('llm.fallback.api_key'),
                    fallbackModel: config('llm.fallback.model'),
                    fallbackBaseUrl: config('llm.fallback.base_url'),
                    useModelParameters: config('llm.openai.use_model_parameters', false),
                ),
                default => throw LLMException::unsupportedProvider((string) config('llm.provider')),
            };
        });
    }
}
