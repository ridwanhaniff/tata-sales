<?php

namespace App\Agents\Support;

use App\Agents\AgentContext;
use App\Agents\Contracts\AgentInterface;
use App\Agents\Values\ToolCall;
use App\Agents\Values\ToolResult;
use App\Models\AiAgentLog;
use Throwable;

/**
 * Jalur eksekusi tool satu-satunya (§32, §118): agent TIDAK PERNAH
 * menyentuh database/service langsung. Semua tool call lewat sini,
 * selalu tercatat di ai_agent_logs — kalau tool tidak terdaftar,
 * call ditolak (denied) dan tercatat, apa pun isi prompt-nya.
 */
class ToolExecutor
{
    /**
     * @return array{replies: array<int, string>, results: ToolResult[]}
     */
    public function run(AgentInterface $agent, AgentContext $context, array $toolCalls): array
    {
        $results = [];

        foreach ($toolCalls as $call) {
            $results[] = $this->execute($agent, $context, $call);
        }

        $replies = array_map(fn (ToolResult $r) => $r->toMessageContent(), $results);

        return ['replies' => $replies, 'results' => $results];
    }

    private function execute(AgentInterface $agent, AgentContext $context, ToolCall $call): ToolResult
    {
        $tool = collect($agent->tools())
            ->first(fn ($candidate) => $candidate->name() === $call->name);

        if (! $tool) {
            $this->log($agent, $context, $call->name, $call->arguments, [], AiAgentLog::STATUS_DENIED, 0);

            return ToolResult::denied($call->name, $call->arguments);
        }

        $startedAt = hrtime(true);

        try {
            $output = $tool->execute($call->arguments);
            $status = AiAgentLog::STATUS_SUCCESS;
        } catch (Throwable $e) {
            $output = ['error' => $e->getMessage()];
            $status = AiAgentLog::STATUS_FAILED;
        }

        $latencyMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        $this->log($agent, $context, $call->name, $call->arguments, $output, $status, $latencyMs);

        return new ToolResult($status, $call->name, $call->arguments, $output, $latencyMs);
    }

    private function log(
        AgentInterface $agent,
        AgentContext $context,
        ?string $toolName,
        array $input,
        array $output,
        string $status,
        int $latencyMs,
    ): void {
        AiAgentLog::create([
            'tenant_id' => AgentTenant::resolve($context),
            'conversation_id' => $context->conversationId,
            'lead_id' => $context->leadId,
            'agent' => $agent->name(),
            'tool_called' => $toolName,
            'input' => $input,
            'output' => $output,
            'status' => $status,
            'latency_ms' => $latencyMs,
            'created_at' => now(),
        ]);
    }
}
