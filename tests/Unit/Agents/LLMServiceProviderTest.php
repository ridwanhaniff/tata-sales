<?php

namespace Tests\Unit\Agents;

use App\Agents\Contracts\LLMProvider;
use App\Agents\Exceptions\LLMException;
use App\Agents\Providers\AnthropicProvider;
use Tests\TestCase;

class LLMServiceProviderTest extends TestCase
{
    public function test_resolves_anthropic_adapter_from_config(): void
    {
        config([
            'llm.provider' => 'anthropic',
            'llm.anthropic.api_key' => 'sk-test',
            'llm.anthropic.model' => 'claude-sonnet-4-5',
        ]);

        $this->assertInstanceOf(AnthropicProvider::class, app(LLMProvider::class));
        $this->assertInstanceOf(LLMProvider::class, app(LLMProvider::class));
    }

    public function test_unsupported_provider_throws(): void
    {
        config(['llm.provider' => 'made-up']);

        try {
            app(LLMProvider::class);
            $this->fail('Seharusnya throw LLMException.');
        } catch (LLMException) {
            $this->assertTrue(true);
        }
    }

    public function test_missing_api_key_fails_fast_at_resolve(): void
    {
        config([
            'llm.provider' => 'anthropic',
            'llm.anthropic.api_key' => '',
        ]);

        $this->expectException(LLMException::class);

        app(LLMProvider::class);
    }
}
