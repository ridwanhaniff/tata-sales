<?php

namespace Tests\Unit\Agents;

use App\Agents\Exceptions\LLMException;
use App\Agents\Providers\AnthropicProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicProviderTest extends TestCase
{
    private function provider(): AnthropicProvider
    {
        return new AnthropicProvider(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-5',
            baseUrl: 'https://api.anthropic.com/v1',
            timeout: 5,
        );
    }

    public function test_constructor_requires_api_key(): void
    {
        $this->expectException(LLMException::class);

        new AnthropicProvider(apiKey: '', model: 'claude-sonnet-4-5');
    }

    public function test_generate_sends_anthropic_messages_and_maps_text(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Halo, ada yang bisa dibantu?']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 15, 'output_tokens' => 7],
            ]),
        ]);

        $response = $this->provider()->generate('Halo?', ['system' => 'Kamu adalah AI penjualan.']);

        $this->assertSame('Halo, ada yang bisa dibantu?', $response->content);
        $this->assertSame([], $response->toolCalls);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request['model'] === 'claude-sonnet-4-5'
                && $request['system'] === 'Kamu adalah AI penjualan.'
                && $request->hasHeader('x-api-key', 'test-key')
                && $request['messages'] === [['role' => 'user', 'content' => 'Halo?']];
        });
    }

    public function test_generate_maps_tool_use_blocks_to_tool_calls(): void
    {
        Http::fake([
            '*' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => ''],
                    ['type' => 'tool_use', 'id' => 't1', 'name' => 'search_products', 'input' => ['query' => 'fronx', 'budget_max' => 300000000]],
                ],
                'stop_reason' => 'tool_use',
            ]),
        ]);

        $response = $this->provider()->generate('Cari mobil');

        $this->assertCount(1, $response->toolCalls);
        $this->assertSame('search_products', $response->toolCalls[0]->name);
        $this->assertSame(['query' => 'fronx', 'budget_max' => 300000000], $response->toolCalls[0]->arguments);
    }

    public function test_tools_are_sent_as_anthropic_input_schema(): void
    {
        Http::fake(['*' => Http::response(['content' => [], 'stop_reason' => 'end_turn'])]);

        $this->provider()->generate('Cari', [], [
            'tools' => [[
                'name' => 'search_products',
                'description' => 'Cari produk',
                'parameters' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
            ]],
        ]);

        Http::assertSent(fn ($request) => $request['tools'] === [[
            'name' => 'search_products',
            'description' => 'Cari produk',
            'input_schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']],
        ]]);
    }

    public function test_empty_prompt_does_not_append_extra_user_message(): void
    {
        Http::fake(['*' => Http::response(['content' => [], 'stop_reason' => 'end_turn'])]);

        $this->provider()->generate('', ['messages' => [['role' => 'user', 'content' => 'pesan lama']]]);

        Http::assertSent(fn ($request) => count($request['messages']) === 1);
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
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => '{"label":"installment","confidence":0.91}']],
            'stop_reason' => 'end_turn',
        ])]);

        $result = $this->provider()->classify('cicilan berapa?', ['price', 'installment', 'promotion']);

        $this->assertSame('installment', $result->label);
        $this->assertSame(0.91, $result->confidence);
    }

    public function test_classify_invalid_json_falls_back_to_unknown(): void
    {
        Http::fake(['*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'installment']],
            'stop_reason' => 'end_turn',
        ])]);

        $result = $this->provider()->classify('cicilan dong', ['price', 'installment']);

        $this->assertSame('installment', $result->label);
        $this->assertGreaterThan(0, $result->confidence);
    }
}
