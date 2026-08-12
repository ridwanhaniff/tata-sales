<?php

namespace App\Agents\Providers;

use App\Agents\Contracts\LLMProvider;
use App\Agents\Exceptions\LLMException;
use App\Agents\Values\ClassificationResult;
use App\Agents\Values\LLMResponse;
use App\Agents\Values\ToolCall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Adapter Anthropic Messages API (§65). Menampilkan format netral
 * (ToolCall[] di LLMResponse) ke agent — agent tidak tahu SDK provider.
 */
class AnthropicProvider implements LLMProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl = 'https://api.anthropic.com/v1',
        private readonly int $timeout = 60,
    ) {
        if ($apiKey === '') {
            throw LLMException::missingApiKey('anthropic');
        }
    }

    public function generate(string $prompt, array $context = [], array $options = []): LLMResponse
    {
        $system = (string) ($context['system'] ?? '');
        $messages = $context['messages'] ?? [];

        if ($prompt !== '') {
            $messages[] = ['role' => 'user', 'content' => $prompt];
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => (int) ($options['max_tokens'] ?? config('llm.anthropic.max_tokens', 1024)),
            'messages' => $messages,
        ];

        if ($system !== '') {
            $payload['system'] = $system;
        }

        $payload['tools'] = array_map(
            fn (array $tool) => [
                'name' => $tool['name'],
                'description' => $tool['description'] ?? '',
                'input_schema' => $tool['parameters'] ?? ['type' => 'object', 'properties' => []],
            ],
            $options['tools'] ?? []
        );

        $startedAt = hrtime(true);

        try {
            $curlOptions = [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4];
            $caFile = (string) config('llm.ca_file', '');
            if ($caFile !== '' && is_file($caFile)) {
                $curlOptions[CURLOPT_CAINFO] = $caFile;
            }

            $response = Http::asJson()
                ->withHeaders([
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => '2023-06-01',
                ])
                ->timeout($this->timeout)
                ->connectTimeout($this->timeout)
                ->withOptions(['curl' => $curlOptions])
                ->post($this->baseUrl.'/messages', $payload)
                ->throw();
        } catch (ConnectionException) {
            throw new LLMException('Provider LLM tidak dapat dijangkau (timeout/network).');
        } catch (Throwable $e) {
            throw new LLMException('Panggilan LLM Anthropic gagal: '.$e->getMessage());
        }

        $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);
        $blocks = $response->json('content') ?? [];

        $text = '';
        $toolCalls = [];
        $stopReason = $response->json('stop_reason');

        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
            if (($block['type'] ?? '') === 'tool_use') {
                $toolCalls[] = new ToolCall(
                    (string) ($block['name'] ?? ''),
                    (array) ($block['input'] ?? []),
                );
            }
        }

        return new LLMResponse(
            content: $text,
            toolCalls: $toolCalls,
            latencyMs: $latencyMs,
            meta: [
                'usage' => $response->json('usage') ?? [],
                'stop_reason' => $stopReason,
            ],
        );
    }

    public function classify(string $input, array $labels, array $context = []): ClassificationResult
    {
        $labelList = implode(', ', $labels);
        $system = $context['system'] ?? null;

        $prompt = <<<PROMPT
Klassifikasikan pesan customer di bawah ke salah satu label ini: {$labelList}.

Balas HANYA dengan JSON satu baris: {"label": "<salah satu label>", "confidence": 0.0-1.0}

Pesan: {$input}
PROMPT;

        try {
            $response = $this->generate($prompt, ['system' => $system], ['max_tokens' => 200]);
        } catch (LLMException $e) {
            Log::warning('[agents] classify gagal, fallback label unknown', ['error' => $e->getMessage()]);

            return new ClassificationResult('unknown', 0.0);
        }

        return $this->parseClassification($response->content, $labels);
    }

    private function parseClassification(string $content, array $labels): ClassificationResult
    {
        $decoded = json_decode(trim($content), true);
        if (is_array($decoded)) {
            $label = (string) ($decoded['label'] ?? '');
            $confidence = (float) ($decoded['confidence'] ?? 0.0);
            if (in_array($label, $labels, true) && $confidence > 0) {
                return new ClassificationResult($label, min($confidence, 1.0), $decoded);
            }
        }

        foreach ($labels as $label) {
            if (str_contains($content, $label)) {
                return new ClassificationResult($label, 0.80, ['parsed' => 'keyword']);
            }
        }

        return new ClassificationResult('unknown', 0.0, ['content' => $content]);
    }

    public function extract(string $input, array $schema, array $context = []): array
    {
        $system = $context['system'] ?? 'Extract JSON sesuai schema.';
        $response = $this->generate(
            'Ekstrak data JSON dari pesan berikut sesuai schema '.json_encode($schema).":\n\n{$input}",
            ['system' => $system],
            ['max_tokens' => 400]
        );

        $decoded = json_decode(trim($response->content), true);

        return is_array($decoded) ? $decoded : [];
    }
}
