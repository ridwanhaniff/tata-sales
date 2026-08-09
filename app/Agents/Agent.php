<?php

namespace App\Agents;

use App\Agents\Contracts\AgentInterface;
use App\Agents\Contracts\LLMProvider;
use App\Agents\Support\ToolExecutor;
use App\Agents\Values\LLMResponse;
use App\Agents\Values\ToolResult;

/**
 * Dasar agent dengan loop tool-calling (§4):
 * 1. LLM menerima system prompt + tools whitelist milik agent ini saja.
 * 2. Kalau LLM minta tool → ToolExecutor mengeksekusi & meng-log semuanya.
 * 3. Output tool dikembalikan ke LLM sebagai konteks — LLM tidak punya
 *    jalur data lain selain output tool (guardrail §31 secara arsitektur).
 * 4. Loop berhenti saat LLM tidak lagi minta tool, atau batas iterasi.
 */
abstract class Agent implements AgentInterface
{
    public function __construct(
        protected readonly LLMProvider $llm,
        protected readonly ToolExecutor $executor,
    ) {}

    abstract protected function systemPrompt(AgentContext $context): string;

    /**
     * Menyusun output final agent dari hasil loop.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  ToolResult[]  $toolResults
     */
    abstract protected function finalize(AgentContext $context, array $messages, array $toolResults, LLMResponse $final): array;

    public function handle(AgentContext $context): array
    {
        $messages = $context->history;
        $system = $this->systemPrompt($context);
        $options = [
            'tools' => collect($this->tools())->map(fn ($tool) => [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'parameters' => $tool->parameters(),
            ])->all(),
        ];

        $response = $this->llm->generate($context->message, [
            'system' => $system,
            'messages' => $messages,
        ], $options);

        $messages[] = ['role' => 'assistant', 'content' => $response->content];

        $toolResults = [];
        $iterations = 0;

        while ($response->hasToolCalls() && $iterations < config('llm.max_tool_iterations', 6)) {
            $batch = $this->executor->run($this, $context, $response->toolCalls);
            $toolResults = array_merge($toolResults, $batch['results']);

            foreach ($batch['replies'] as $reply) {
                $messages[] = ['role' => 'user', 'content' => $reply];
            }

            $response = $this->llm->generate('', [
                'system' => $system,
                'messages' => $messages,
            ], $options);

            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $iterations++;
        }

        return $this->finalize($context, $messages, $toolResults, $response);
    }
}
