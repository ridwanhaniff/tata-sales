<?php

namespace Tests\Unit\Agents;

use App\Agents\Exceptions\LLMException;
use App\Agents\Providers\OpenAICompatibleProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAICompatibleProviderTest extends TestCase
{
    private function provider(): OpenAICompatibleProvider
    {
        return new OpenAICompatibleProvider(
            apiKey: 'gemini-key',
            model: 'gemini-3-flash',
            baseUrl: 'https://generativelanguage.googleapis.com/v1beta/openai/',
            timeout: 5,
            fallbackApiKey: 'groq-key',
            fallbackModel: 'llama-3.3-70b-versatile',
            fallbackBaseUrl: 'https://api.groq.com/openai/v1',
        );
    }

    private function chatBody(string $text = 'Halo!', ?array $toolCalls = null, string $finish = 'stop'): array
    {
        $message = ['role' => 'assistant', 'content' => $text];

        if ($toolCalls !== null) {
            $message['tool_calls'] = $toolCalls;
        }

        return [
            'choices' => [
                ['message' => $message, 'finish_reason' => $finish],
            ],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 5, 'total_tokens' => 25],
        ];
    }

    public function test_constructor_requires_api_key(): void
    {
        $this->expectException(LLMException::class);

        new OpenAICompatibleProvider(apiKey: '', model: 'gemini-3-flash', baseUrl: 'https://x/');
    }

    public function test_generate_posts_chat_completions_and_maps_response(): void
    {
        Http::fake([
            '*' => Http::response($this->chatBody('Halo, ada yang bisa dibantu?')),
        ]);

        $response = $this->provider()->generate('Halo?', ['system' => 'Kamu adalah AI penjualan.']);

        $this->assertSame('Halo, ada yang bisa dibantu?', $response->content);
        $this->assertSame([], $response->toolCalls);
        $this->assertSame('stop', $response->meta['stop_reason']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer gemini-key')
                && $request['model'] === 'gemini-3-flash'
                && $request['messages'] === [
                    ['role' => 'system', 'content' => 'Kamu adalah AI penjualan.'],
                    ['role' => 'user', 'content' => 'Halo?'],
                ];
        });
    }

    public function test_generate_maps_tool_calls(): void
    {
        Http::fake([
            '*' => Http::response($this->chatBody('', [
                [
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'search_products',
                        'arguments' => '{"query":"fronx","budget_max":300000000}',
                    ],
                ],
            ], 'tool_calls')),
        ]);

        $response = $this->provider()->generate('Cari mobil');

        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('search_products', $response->toolCalls[0]->name);
        $this->assertSame(['query' => 'fronx', 'budget_max' => 300000000], $response->toolCalls[0]->arguments);
        $this->assertSame('tool_calls', $response->meta['stop_reason']);
    }

    public function test_tools_are_sent_in_openai_function_format(): void
    {
        Http::fake(['*' => Http::response($this->chatBody())]);

        $this->provider()->generate('Cari', [], [
            'tools' => [[
                'name' => 'search_products',
                'description' => 'Cari produk',
                'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
            ]],
        ]);

        Http::assertSent(fn ($request) => $request['tools'] === [[
            'type' => 'function',
            'function' => [
                'name' => 'search_products',
                'description' => 'Cari produk',
                'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
            ],
        ]]);
    }

    public function test_use_model_parameters_wraps_max_tokens_zen_style(): void
    {
        Http::fake(['*' => Http::response($this->chatBody('Halo dari Zen.'))]);

        $provider = new OpenAICompatibleProvider(
            apiKey: 'zen-key',
            model: 'deepseek-v4-flash-free',
            baseUrl: 'https://opencode.ai/zen/v1',
            timeout: 5,
            useModelParameters: true,
        );

        $response = $provider->generate('Halo');

        $this->assertSame('Halo dari Zen.', $response->content);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://opencode.ai/zen/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer zen-key')
                && $request['model'] === 'deepseek-v4-flash-free'
                && $request['model_parameters'] === ['params' => ['max_tokens' => 1024]]
                && ! array_key_exists('max_tokens', $request->data());
        });
    }

    public function test_429_falls_back_to_groq_once(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response('rate limited', 429),
            'https://api.groq.com/*' => Http::response($this->chatBody('Balasan dari fallback.')),
        ]);

        $response = $this->provider()->generate('Halo');

        $this->assertSame('Balasan dari fallback.', $response->content);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://generativelanguage.googleapis.com/')
                && $request['model'] === 'gemini-3-flash';
        });

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
                && $request['model'] === 'llama-3.3-70b-versatile'
                && $request->hasHeader('Authorization', 'Bearer groq-key');
        });
    }

    public function test_429_without_fallback_configured_throws(): void
    {
        Config::set('llm.fallback.enabled', false);

        Http::fake([
            '*' => Http::response('rate limited', 429),
        ]);

        $this->expectException(LLMException::class);
        $this->expectExceptionMessage('429');

        $this->provider()->generate('Halo');
    }

    public function test_http_error_throws_llm_exception(): void
    {
        Http::fake(['*' => Http::response('unauthorized', 401)]);

        $this->expectException(LLMException::class);

        $this->provider()->generate('x');
    }

    public function test_connection_timeout_throws_llm_exception(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('Connection timed out')]);

        $this->expectException(LLMException::class);

        $this->provider()->generate('x');
    }

    public function test_classify_parses_json_label_and_confidence(): void
    {
        Http::fake(['*' => Http::response($this->chatBody('{"label":"installment","confidence":0.91}'))]);

        $result = $this->provider()->classify('cicilan berapa?', ['price', 'installment', 'promotion']);

        $this->assertSame('installment', $result->label);
        $this->assertSame(0.91, $result->confidence);
    }

    public function test_extract_returns_decoded_json(): void
    {
        Http::fake(['*' => Http::response($this->chatBody('{"name":"Budi","phone":"08123456"}'))]);

        $result = $this->provider()->extract('Nama saya Budi, nomor 08123456', [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string'], 'phone' => ['type' => 'string']],
        ]);

        $this->assertSame(['name' => 'Budi', 'phone' => '08123456'], $result);
    }
}
