<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Workflow\StoreWorkflowRequest;
use App\Http\Resources\WorkflowResource;
use App\Models\Workflow;
use App\Models\WorkflowNode;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkflowController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $workflows = Workflow::query()
            ->with('nodes')
            ->when($request->filled('trigger_event'), fn ($q) => $q->where('trigger_event', $request->string('trigger_event')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success(WorkflowResource::collection($workflows));
    }

    public function store(StoreWorkflowRequest $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')?->id;
        $data = $request->validated();

        $workflow = DB::transaction(function () use ($data, $tenantId) {
            $workflow = Workflow::create([
                'tenant_id' => $tenantId,
                'name' => $data['name'],
                'trigger_event' => $data['trigger_event'],
                'status' => $data['status'] ?? 'active',
                'definition' => $data['nodes'],
            ]);

            foreach ($data['nodes'] as $index => $node) {
                $this->createNode($workflow, $node, $index);
            }

            return $workflow;
        });

        return ApiResponse::created(new WorkflowResource($workflow->load('nodes')));
    }

    public function show(Workflow $workflow): JsonResponse
    {
        return ApiResponse::success(new WorkflowResource($workflow->load(['nodes', 'runs'])));
    }

    public function update(StoreWorkflowRequest $request, Workflow $workflow): JsonResponse
    {
        $data = $request->validated();

        $workflow = DB::transaction(function () use ($data, $workflow) {
            $workflow->update([
                'name' => $data['name'],
                'trigger_event' => $data['trigger_event'],
                'status' => $data['status'] ?? $workflow->status,
                'definition' => $data['nodes'],
            ]);

            WorkflowNode::query()->where('workflow_id', $workflow->id)->delete();

            foreach ($data['nodes'] as $index => $node) {
                $this->createNode($workflow, $node, $index);
            }

            return $workflow;
        });

        return ApiResponse::success(new WorkflowResource($workflow->load('nodes')));
    }

    public function destroy(Workflow $workflow): JsonResponse
    {
        $workflow->delete();

        return ApiResponse::noContent();
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function createNode(Workflow $workflow, array $node, int $index): WorkflowNode
    {
        return WorkflowNode::create([
            'tenant_id' => $workflow->tenant_id,
            'workflow_id' => $workflow->id,
            'node_type' => $node['node_type'],
            'config' => $node['config'] ?? [],
            'sort_order' => $node['sort_order'] ?? $index,
            'next_node_id' => $node['next_node_id'] ?? null,
        ]);
    }
}
