<?php

namespace App\Services\Workflow;

use App\Agents\AgentContext;
use App\Agents\Contracts\LLMProvider;
use App\Agents\FollowupAgent;
use App\Agents\Support\ToolExecutor;
use App\Models\Followup;
use App\Models\FollowupStep;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowLog;
use App\Models\WorkflowNode;
use App\Models\WorkflowRun;
use App\Services\FollowUp\FollowUpService;
use App\Services\Lead\LeadService;
use App\Services\Notification\NotificationService;
use App\Support\ConditionEvaluator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * WorkflowEngine v1 (§34): trigger → condition → action/delay/ai/human/end.
 * Node `ai` menjalankan agent (saat ini Follow-up Agent) dalam isolasi —
 * kegagalan LLM tidak pernah menggagalkan run (lead tetap tersimpan).
 * Node `human` = hard-stop: run berhenti di status waiting_human menunggu
 * aksi sales, lanjut via resume() setelah sales tuntas (notifikasi dikirim
 * ke sales terkait + owner/manager tenant).
 *
 * Actions didukung:
 *  - create_followup   {message, delay_minutes, channel}
 *  - lead_transition   {to}
 *  - notify_sales      {title, body}
 */
class WorkflowEngine
{
    public function __construct(
        private readonly FollowUpService $followUps,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Jalankan semua workflow aktif untuk event pada lead.
     *
     * @param  array<string, mixed>  $context
     */
    public function trigger(string $event, Lead $lead, array $context = []): void
    {
        $workflows = Workflow::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $lead->tenant_id)
            ->where('status', 'active')
            ->where('trigger_event', $event)
            ->with(['nodes' => fn ($q) => $q->withoutGlobalScope('tenant')->orderBy('sort_order')])
            ->get();

        foreach ($workflows as $workflow) {
            $this->dispatch($workflow, $lead, $context);
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function dispatch(Workflow $workflow, Lead $lead, array $context): void
    {
        $duplicate = WorkflowRun::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $lead->tenant_id)
            ->where('workflow_id', $workflow->id)
            ->where('lead_id', $lead->id)
            ->where('status', WorkflowRun::STATUS_RUNNING)
            ->exists();

        if ($duplicate) {
            return;
        }

        $run = WorkflowRun::create([
            'tenant_id' => $lead->tenant_id,
            'workflow_id' => $workflow->id,
            'lead_id' => $lead->id,
            'status' => WorkflowRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        try {
            DB::beginTransaction();

            $nodes = $workflow->nodes->sortBy('sort_order')->values();
            $ctx = array_merge($this->baseContext($lead), $context);

            $stopped = false;
            $this->advance($nodes, $run, $ctx, 0, $stopped);

            if ($stopped) {
                // hard-stop di node human: run menunggu aksi sales
                $run->forceFill(['status' => WorkflowRun::STATUS_WAITING_HUMAN])->save();
            } else {
                $run->forceFill([
                    'status' => WorkflowRun::STATUS_COMPLETED,
                    'current_node_id' => null,
                    'finished_at' => now(),
                ])->save();
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            $run->forceFill(['status' => WorkflowRun::STATUS_FAILED, 'finished_at' => now()])->save();

            WorkflowLog::create([
                'tenant_id' => $lead->tenant_id,
                'workflow_run_id' => $run->id,
                'status' => WorkflowRun::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resume run yang berhenti di node human (hard-stop §34). Eksekusi
     * berlanjut dari node setelah current_node_id; kalau berhenti lagi di
     * node human berikutnya, status kembali waiting_human.
     *
     * Context tidak dipersist-kan di run — dibangun ulang dari lead + argumen.
     *
     * @param  array<string, mixed>  $context
     */
    public function resume(WorkflowRun $run, array $context = []): bool
    {
        if ($run->status !== WorkflowRun::STATUS_WAITING_HUMAN || ! $run->current_node_id) {
            return false;
        }

        $nodes = WorkflowNode::query()
            ->withoutGlobalScope('tenant')
            ->where('workflow_id', $run->workflow_id)
            ->orderBy('sort_order')
            ->get()
            ->values();

        $start = null;
        foreach ($nodes as $index => $node) {
            if ($node->id === $run->current_node_id) {
                $start = $index + 1;
                break;
            }
        }

        if ($start === null || $start >= $nodes->count()) {
            // node human adalah node terakhir — resume = selesai
            $run->forceFill([
                'status' => WorkflowRun::STATUS_COMPLETED,
                'current_node_id' => null,
                'finished_at' => now(),
            ])->save();

            return true;
        }

        $lead = Lead::query()->withoutGlobalScope('tenant')->find($run->lead_id);

        try {
            DB::beginTransaction();

            $ctx = array_merge($this->baseContext($lead), $context);

            $stopped = false;
            $this->advance($nodes, $run, $ctx, $start, $stopped);

            if ($stopped) {
                $run->forceFill(['status' => WorkflowRun::STATUS_WAITING_HUMAN])->save();
            } else {
                $run->forceFill([
                    'status' => WorkflowRun::STATUS_COMPLETED,
                    'current_node_id' => null,
                    'finished_at' => now(),
                ])->save();
            }

            DB::commit();

            return true;
        } catch (\Throwable $e) {
            DB::rollBack();

            $run->forceFill(['status' => WorkflowRun::STATUS_FAILED, 'finished_at' => now()])->save();

            WorkflowLog::create([
                'tenant_id' => $run->tenant_id,
                'workflow_run_id' => $run->id,
                'status' => WorkflowRun::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Iterasi node workflow dari indeks $start. Berhenti dini saat node
     * `end` (break) atau node `human` (hard-stop, $stopped = true).
     *
     * @param  Collection<int, WorkflowNode>  $nodes
     * @param  array<string, mixed>  $ctx
     */
    private function advance(Collection $nodes, WorkflowRun $run, array $ctx, int $start, bool &$stopped): void
    {
        foreach ($nodes as $index => $node) {
            if ($index < $start) {
                continue;
            }

            if ($node->node_type === 'trigger') {
                $this->log($run, $node, 'success', $ctx, $ctx);

                continue;
            }

            if ($node->node_type === 'end') {
                $this->log($run, $node, 'success', $ctx, ['ended' => true]);
                break;
            }

            $run->forceFill(['current_node_id' => $node->id])->save();

            if ($node->node_type === 'ai') {
                $this->executeAiNode($run, $node, $ctx);

                continue;
            }

            if ($node->node_type === 'human') {
                $this->executeHumanNode($run, $node, $ctx);
                $stopped = true;

                return;
            }

            $abort = false;
            $this->executeNode($run, $node, $ctx, $abort);

            if ($abort) {
                break;
            }
        }
    }

    /**
     * Node human = hard-stop (§5.4): run menunggu aksi sales sebelum lanjut.
     * Sales yang ditugasi + owner/manager tenant dinotifikasi; posisi berhenti
     * dicatat di current_node_id untuk resume().
     *
     * @param  array<string, mixed>  $ctx
     */
    private function executeHumanNode(WorkflowRun $run, WorkflowNode $node, array $ctx): void
    {
        $notified = [];

        if (! empty($ctx['assigned_to'])) {
            $this->notifyHumanGuard($run, $ctx['assigned_to'], $node);
            $notified[] = (string) $ctx['assigned_to'];
        }

        foreach (User::query()->where('tenant_id', $run->tenant_id)->whereIn('role', ['owner', 'manager'])->pluck('id') as $userId) {
            if (! in_array($userId, $notified, true)) {
                $this->notifyHumanGuard($run, $userId, $node);
                $notified[] = (string) $userId;
            }
        }

        $this->log($run, $node, 'success', $ctx, [
            'waiting' => true,
            'notified' => $notified,
        ]);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function notifyHumanGuard(WorkflowRun $run, string|int $userId, WorkflowNode $node): void
    {
        $this->notifications->notify(
            $run->tenant_id,
            $userId,
            'workflow_human',
            'Workflow butuh aksi manusia',
            (string) ($node->config['note'] ?? 'Lihat lead dan lanjutkan workflow setelah penanganan.'),
            ['workflow_run_id' => $run->id, 'lead_id' => $run->lead_id, 'node_id' => $node->id],
            'dashboard',
        );
    }

    /**
     * Node ai: jalankan agent sesuai config (saat ini 'followup'). Selalu
     * dalam isolasi — exception dari LLM/tool hanya dicatat, run workflow
     * tetap lanjut & completed (lead tidak pernah ke-rollback gara-gara AI).
     *
     * @param  array<string, mixed>  $ctx
     */
    private function executeAiNode(WorkflowRun $run, WorkflowNode $node, array $ctx): void
    {
        $agentName = (string) ($node->config['agent'] ?? '');

        try {
            $output = match ($agentName) {
                'followup' => $this->runFollowupAgent($run, $node, $ctx),
                default => null,
            };

            if ($output === null) {
                $this->log($run, $node, 'skipped', $ctx, ['unsupported_agent' => $agentName]);

                return;
            }

            $this->log($run, $node, 'success', $ctx, $output);
        } catch (\Throwable $e) {
            $this->log($run, $node, 'failed', $ctx, ['error' => $e->getMessage()]);
        }
    }

    /**
     * Agent followup dipanggil dengan context lead + followup_step dari
     * config node. currentTenant di-bind ke tenant run selama eksekusi
     * supaya scope tenant & RLS konsisten walau dijalankan job.
     *
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>|null null = preflight gagal / agent tak didukung
     */
    private function runFollowupAgent(WorkflowRun $run, WorkflowNode $node, array $ctx): ?array
    {
        $stepId = (string) ($node->config['step_id'] ?? '');
        $lead = Lead::query()->withoutGlobalScope('tenant')->find($run->lead_id);

        if (! $lead || $stepId === '') {
            return null;
        }

        $step = FollowupStep::query()->withoutGlobalScope('tenant')->find($stepId);

        if (! $step || $step->status !== 'active' || $step->tenant_id !== $run->tenant_id) {
            return null;
        }

        $tenant = Tenant::query()->find($run->tenant_id);

        $previousTenant = app()->bound('currentTenant') ? app('currentTenant') : null;
        $previousConversation = app()->bound('currentConversation') ? app('currentConversation') : null;

        if ($tenant) {
            app()->instance('currentTenant', $tenant);
        }
        app()->instance('currentConversation', null);

        try {
            $agent = new FollowupAgent(app(LLMProvider::class), new ToolExecutor);

            $result = $agent->handle(new AgentContext(
                message: 'Tulis draft pesan follow-up untuk lead ini.',
                tenant: $tenant,
                leadId: $lead->id,
                meta: [
                    'lead' => [
                        'id' => $lead->id,
                        'status' => $lead->status,
                        'estimated_value' => $lead->estimated_value,
                        'product_id' => $lead->product_id,
                    ],
                    'followup_step' => [
                        'id' => $step->id,
                        'name' => $step->name,
                        'action' => $step->action,
                        'delay_minutes' => $step->delay_minutes,
                    ],
                ],
            ));
        } finally {
            app()->instance('currentTenant', $previousTenant);
            app()->instance('currentConversation', $previousConversation);
        }

        return [
            'followup_id' => $result['followup_id'] ?? null,
            'scheduled_at' => $result['scheduled_at'] ?? null,
            'reply' => $result['reply'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function executeNode(WorkflowRun $run, WorkflowNode $node, array &$ctx, bool &$abort): void
    {
        switch ($node->node_type) {
            case 'condition':
                $passes = ConditionEvaluator::passes($ctx, $node->config);
                $this->log($run, $node, $passes ? 'success' : 'skipped', $ctx, ['passes' => $passes]);
                $abort = ! $passes;
                break;

            case 'delay':
                $minutes = (int) ($node->config['minutes'] ?? 0);
                $followup = Followup::create([
                    'tenant_id' => $run->tenant_id,
                    'lead_id' => $run->lead_id,
                    'assigned_to' => $ctx['assigned_to'] ?? null,
                    'status' => 'pending',
                    'channel' => 'workflow',
                    'scheduled_at' => now()->addMinutes(max(1, $minutes)),
                    'message' => $node->config['note'] ?? null,
                    'created_at' => now(),
                ]);
                $this->log($run, $node, 'success', $ctx, ['followup_id' => $followup->id]);
                break;

            case 'action':
                $action = (string) ($node->config['action'] ?? 'noop');
                $this->runAction($run, $node, $ctx, $action);
                break;

            default:
                $this->log($run, $node, 'skipped', $ctx, ['unsupported' => $node->node_type]);
        }
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function runAction(WorkflowRun $run, WorkflowNode $node, array &$ctx, string $action): void
    {
        $output = [];

        switch ($action) {
            case 'create_followup':
                $delay = (int) ($node->config['delay_minutes'] ?? 60);
                $followup = Followup::create([
                    'tenant_id' => $run->tenant_id,
                    'lead_id' => $run->lead_id,
                    'assigned_to' => $ctx['assigned_to'] ?? null,
                    'status' => 'pending',
                    'channel' => (string) ($node->config['channel'] ?? 'whatsapp'),
                    'scheduled_at' => now()->addMinutes(max(1, $delay)),
                    'message' => $ctx['customer_name'] ? str_replace(
                        ['{customer_name}', '{product_name}'],
                        [$ctx['customer_name'], $ctx['product_name'] ?? 'produk'],
                        (string) ($node->config['message'] ?? '')
                    ) : null,
                    'created_at' => now(),
                ]);
                $output = ['followup_id' => $followup->id];
                break;

            case 'lead_transition':
                $to = (string) ($node->config['to'] ?? '');
                if ($to !== '') {
                    $lead = Lead::query()->withoutGlobalScope('tenant')->find($run->lead_id);
                    if ($lead) {
                        app(LeadService::class)->transition($lead, $to);
                        $ctx['status'] = $to;
                        $output = ['to' => $to];
                    }
                }
                break;

            case 'notify_sales':
                $user = $ctx['assigned_to'] ?? null;
                if ($user) {
                    $this->notifications->notify(
                        $run->tenant_id,
                        $user,
                        'workflow_notify',
                        (string) ($node->config['title'] ?? 'Notifikasi workflow'),
                        $node->config['body'] ?? null,
                        ['workflow_run_id' => $run->id, 'lead_id' => $run->lead_id]
                    );
                    $output = ['notified' => $user];
                }
                break;

            default:
                $this->log($run, $node, 'skipped', $ctx, ['unknown_action' => $action]);

                return;
        }

        $this->log($run, $node, 'success', $ctx, $output);
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<string, mixed>  $output
     */
    private function log(WorkflowRun $run, WorkflowNode $node, string $status, array $ctx, array $output): void
    {
        WorkflowLog::create([
            'tenant_id' => $run->tenant_id,
            'workflow_run_id' => $run->id,
            'node_id' => $node->id,
            'status' => $status,
            'input' => $ctx,
            'output' => $output,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseContext(?Lead $lead): array
    {
        return [
            'lead_id' => $lead?->id,
            'status' => $lead?->status,
            'temperature' => $lead?->temperature,
            'score' => $lead?->score,
            'source' => $lead?->source,
            'assigned_to' => $lead?->assigned_to,
            'customer_name' => $lead?->customer?->name,
            'product_name' => $lead?->product?->name,
            'customer.phone' => $lead?->customer?->phone,
        ];
    }
}
