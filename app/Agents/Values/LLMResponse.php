<?php

namespace App\Agents\Values;

class LLMResponse
{
    /**
     * @param  ToolCall[]  $toolCalls
     */
    public function __construct(
        public readonly string $content,
        public readonly array $toolCalls = [],
        public readonly ?int $latencyMs = null,
        public readonly array $meta = [],
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
