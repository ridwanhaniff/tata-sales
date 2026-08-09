<?php

namespace Tests\Support;

use App\Agents\Contracts\LLMProvider;
use App\Agents\Values\ClassificationResult;
use App\Agents\Values\LLMResponse;
use App\Agents\Values\ToolCall;

/**
 * LLMProvider scripted untuk test — tidak pernah menyentuh network.
 * Setiap panggilan generate() "memutar" satu step berikutnya; semuanya
 * tercatat di history supaya test bisa membuktikan apa yang "dilihat" LLM
 * (dipakai untuk guardrail §68: AI hanya melihat data dari tool).
 */
class FakeLLMProvider implements LLMProvider
{
    /**
     * @var array<int, LLMResponse|callable(array): LLMResponse>
     */
    private array $steps;

    /**
     * Semua pesan yang pernah diteruskan ke "LLM".
     *
     * @var array<int, array{role: string, content: string}>
     */
    public array $sessions = [];

    public int $generateCalls = 0;

    public function __construct(array $steps = [])
    {
        $this->steps = $steps;
    }

    public function generate(string $prompt, array $context = [], array $options = []): LLMResponse
    {
        $this->generateCalls++;

        foreach ($context['messages'] ?? [] as $message) {
            $this->sessions[] = $message;
        }
        if ($prompt !== '') {
            $this->sessions[] = ['role' => 'user', 'content' => $prompt];
        }

        $step = array_shift($this->steps);

        if ($step === null) {
            throw new \LogicException('FakeLLMProvider kehabisan step pada panggilan ke-'.$this->generateCalls);
        }

        return is_callable($step) ? $step($context) : $step;
    }

    public function classify(string $input, array $labels, array $context = []): ClassificationResult
    {
        return new ClassificationResult($labels[0] ?? 'unknown', 0.9);
    }

    public function extract(string $input, array $schema, array $context = []): array
    {
        return [];
    }

    public static function text(string $content): LLMResponse
    {
        return new LLMResponse(content: $content);
    }

    public static function toolCall(string $name, array $arguments): LLMResponse
    {
        return new LLMResponse(
            content: '',
            toolCalls: [new ToolCall($name, $arguments)],
        );
    }

    public static function toolCallThenText(string $tool, array $arguments, string $content): array
    {
        return [self::toolCall($tool, $arguments), self::text($content)];
    }
}
