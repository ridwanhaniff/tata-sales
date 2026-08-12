<?php

namespace App\Agents\Providers;

use App\Agents\Contracts\LLMProvider;
use App\Agents\Exceptions\LLMException;
use App\Agents\Values\ClassificationResult;
use App\Agents\Values\LLMResponse;
use App\Agents\Values\ToolCall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Adapter LLM OpenAI-compatible (§65) — satu adapter untuk Gemini
 * (default), Groq, GitHub Models, atau OpenRouter; cukup ganti
 * base_url + model di config/llm.php.
 *
 * Saat provider utama merespons HTTP 429 (rate limit), request dicoba
 * ulang sekali ke `llm.fallback` (default: Groq) — tanpa kartu, murah,
 * dan menjaga agent tetap jalan saat kuota Gemini harian habis.
 */
class OpenAICompatibleProvider implements LLMProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly int $timeout = 60,
        private readonly ?string $fallbackApiKey = null,
        private readonly ?string $fallbackModel = null,
        private readonly ?string $fallbackBaseUrl = null,
        private readonly bool $useModelParameters = false,
    ) {
        if ($this->apiKey === '') {
            throw LLMException::missingApiKey('openai');
        }
    }

    public function generate(string $prompt, array $context = [], array $options = []): LLMResponse
    {
        $messages = $this->buildMessages($prompt, $context);
        $maxTokens = (int) ($options['max_tokens'] ?? config('llm.openai.max_tokens', 1024));

        // OpenCode Zen tidak menerima max_tokens di top-level; harus dibungkus
        // model_parameters.params. Diaktifkan lewat LLM_USE_MODEL_PARAMETERS.
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
        ];
        if ($this->useModelParameters) {
            $payload['model_parameters'] = ['params' => ['max_tokens' => $maxTokens]];
        } else {
            $payload['max_tokens'] = $maxTokens;
        }

        $tools = $options['tools'] ?? [];
        if ($tools !== []) {
            $payload['tools'] = array_map(
                fn (array $tool) => [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool['name'],
                        'description' => $tool['description'] ?? '',
                        'parameters' => $tool['parameters'] ?? ['type' => 'object', 'properties' => []],
                    ],
                ],
                $tools
            );
        }

        $startedAt = hrtime(true);

        try {
            $response = $this->post($this->baseUrl, $payload);
        } catch (LLMException $e) {
            if ($e->rateLimited() && $this->fallbackConfigured()) {
                Log::warning('[llm] 429 pada provider utama, fallback ke model cadangan', [
                    'model' => $this->model,
                    'fallback_model' => $this->fallbackModel,
                ]);

                $payload['model'] = $this->fallbackModel;
                $response = $this->post($this->fallbackBaseUrl ?? '', $payload, $this->fallbackApiKey);
            } else {
                throw $e;
            }
        }

        $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        return $this->parseResponse($response, $latencyMs);
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function buildMessages(string $prompt, array $context): array
    {
        $messages = $context['messages'] ?? [];

        if (! is_array($messages)) {
            $messages = [];
        }

        if (($context['system'] ?? '') !== '') {
            array_unshift($messages, [
                'role' => 'system',
                'content' => (string) $context['system'],
            ]);
        }

        if ($prompt !== '') {
            $messages[] = ['role' => 'user', 'content' => $prompt];
        }

        return $messages;
    }

    private function post(string $baseUrl, array $payload, ?string $apiKey = null): Response
    {
        $curlOptions = [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4];
        $caFile = (string) config('llm.ca_file', '');
        if ($caFile !== '' && is_file($caFile)) {
            $curlOptions[CURLOPT_CAINFO] = $caFile;
        }

        try {
            return Http::asJson()
                ->withToken($apiKey ?? $this->apiKey)
                ->timeout($this->timeout)
                ->connectTimeout($this->timeout)
                ->withOptions([
                    // opencode.ai punya record AAAA (IPv6) yang tidak
                    // terjangkau dari jaringan lokal; cURL PHP menggantung
                    // di connect IPv6 sampai timeout. Paksa IPv4.
                    'curl' => $curlOptions,
                ])
                ->post(rtrim($baseUrl, '/').'/chat/completions', $payload)
                ->throw();
        } catch (ConnectionException $e) {
            throw new LLMException('Provider LLM tidak dapat dijangkau (timeout/network).');
        } catch (Throwable $e) {
            throw LLMException::fromHttp($e, (string) $payload['model']);
        }
    }

    private function fallbackConfigured(): bool
    {
        return config('llm.fallback.enabled', true)
            && $this->fallbackApiKey !== null
            && $this->fallbackApiKey !== ''
            && $this->fallbackBaseUrl !== null
            && $this->fallbackBaseUrl !== '';
    }

    private function parseResponse(Response $response, int $latencyMs): LLMResponse
    {
        $choice = $response->json('choices.0') ?? [];
        $message = $choice['message'] ?? [];

        $text = (string) ($message['content'] ?? '');

        $toolCalls = [];
        foreach ($message['tool_calls'] ?? [] as $call) {
            $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);

            $toolCalls[] = new ToolCall(
                (string) ($call['function']['name'] ?? ''),
                is_array($arguments) ? $arguments : [],
            );
        }

        return new LLMResponse(
            content: $text,
            toolCalls: $toolCalls,
            latencyMs: $latencyMs,
            meta: [
                'usage' => $response->json('usage') ?? [],
                'stop_reason' => $choice['finish_reason'] ?? null,
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
