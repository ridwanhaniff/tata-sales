<?php

namespace App\Services\Workflow;

use App\Models\Followup;
use App\Models\Lead;
use App\Models\Workflow;
use App\Models\WorkflowLog;
use App\Models\WorkflowNode;
use App\Models\WorkflowRun;
use App\Services\FollowUp\FollowUpService;
use App\Services\Lead\LeadService;
use App\Services\Notification\NotificationService;
use App\Support\ConditionEvaluator;
use Illuminate\Support\Facades\DB;

/**
 * WorkflowEngine v1 (§34): trigger → condition → action/delay/end.
 * Node `ai`/`human` di-stub: dicatat skipped (diisi penuh Sprint 9-11).
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
            ->where('status', 'running')
            ->exists();

        if ($duplicate) {
            return;
        }

        $run = WorkflowRun::create([
            'tenant_id' => $lead->tenant_id,
            'workflow_id' => $workflow->id,
            'lead_id' => $lead->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            DB::beginTransaction();

            $script = $workflow->nodes->sortBy('sort_order')->values();
            $ctx = array_merge($this->baseContext($lead), $context);

            foreach ($script as $node) {
                if ($node->node_type === 'trigger') {
                    $this->log($run, $node, 'success', $ctx, $ctx);

                    continue;
                }

                if ($node->node_type === 'end') {
                    $this->log($run, $node, 'success', $ctx, ['ended' => true]);
                    break;
                }

                $run->forceFill(['current_node_id' => $node->id])->save();

                $abort = false;

                if ($node->node_type === 'ai' || $node->node_type === 'human') {
                    $this->log($run, $node, 'skipped', $ctx, ['stub' => $node->node_type]);

                    continue;
                }

                $this->executeNode($run, $node, $ctx, $abort);

                if ($abort) {
                    break;
                }
            }

            $run->forceFill([
                'status' => 'completed',
                'current_node_id' => null,
                'finished_at' => now(),
            ])->save();

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            $run->forceFill(['status' => 'failed', 'finished_at' => now()])->save();

            WorkflowLog::create([
                'tenant_id' => $lead->tenant_id,
                'workflow_run_id' => $run->id,
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
        }
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
    private function baseContext(Lead $lead): array
    {
        return [
            'lead_id' => $lead->id,
            'status' => $lead->status,
            'temperature' => $lead->temperature,
            'score' => $lead->score,
            'source' => $lead->source,
            'assigned_to' => $lead->assigned_to,
            'customer_name' => $lead->customer?->name,
            'product_name' => $lead->product?->name,
            'customer.phone' => $lead->customer?->phone,
        ];
    }
}
