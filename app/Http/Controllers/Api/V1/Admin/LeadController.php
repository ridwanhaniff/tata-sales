<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lead\AssignLeadRequest;
use App\Http\Requests\Lead\StoreLeadNoteRequest;
use App\Http\Requests\Lead\UpdateLeadRequest;
use App\Http\Resources\LeadResource;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\LeadPolicy;
use App\Services\Lead\AssignmentService;
use App\Services\Lead\LeadService;
use App\Services\Webhook\OutboundWebhookService;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LeadController extends Controller
{
    public function __construct(
        private readonly LeadService $service,
        private readonly AssignmentService $assignment,
        private readonly OutboundWebhookService $webhooks,
    ) {
        Gate::policy(Lead::class, LeadPolicy::class);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        Gate::forUser($user)->authorize('viewAny', Lead::class);

        $leads = Lead::query()
            ->with(['customer', 'product', 'assignedUser'])
            ->when($user->role === User::ROLE_SALES, fn ($q) => $q->where('assigned_to', $user->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('temperature'), fn ($q) => $q->where('temperature', $request->string('temperature')))
            ->when($request->filled('sales'), fn ($q) => $q->where('assigned_to', $request->string('sales')))
            ->when($request->filled('product'), fn ($q) => $q->where('product_id', $request->string('product')))
            ->when($request->filled('campaign'), fn ($q) => $q->where('campaign_id', $request->string('campaign')))
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->string('source')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->string('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->string('date_to')))
            ->when($request->filled('location'), function ($q) use ($request) {
                $q->whereHas('customer', fn ($q) => $q->where('location', 'ilike', '%'.$request->string('location').'%'));
            })
            ->orderByDesc('created_at')
            ->paginate(min($request->integer('per_page', 20), 100));

        return ApiResponse::paginated($leads, LeadResource::class);
    }

    public function show(Request $request, Lead $lead): JsonResponse
    {
        Gate::forUser($request->user())->authorize('view', $lead);

        return ApiResponse::success(new LeadResource($lead->load(['customer', 'product', 'assignedUser', 'campaign', 'events'])));
    }

    public function update(UpdateLeadRequest $request, Lead $lead): JsonResponse
    {
        Gate::forUser($request->user())->authorize('update', $lead);

        if ($request->has('status')) {
            $this->service->transition($lead, $request->string('status')->toString(), $request->user());
        }

        if ($request->filled('estimated_value')) {
            $lead->forceFill(['estimated_value' => $request->float('estimated_value')])->save();

            $tenant = Tenant::query()
                ->withoutGlobalScope('tenant')
                ->find($lead->tenant_id);

            if ($tenant) {
                $this->webhooks->dispatch($tenant, 'lead.updated', [
                    'lead_id' => $lead->id,
                    'estimated_value' => (float) $lead->estimated_value,
                    'customer_id' => $lead->customer_id,
                    'product_id' => $lead->product_id,
                ]);
            }
        }

        return ApiResponse::success(new LeadResource($lead->fresh(['customer', 'product', 'assignedUser'])));
    }

    public function assign(AssignLeadRequest $request, Lead $lead): JsonResponse
    {
        Gate::forUser($request->user())->authorize('assign', $lead);

        $sales = User::query()->findOrFail($request->string('user_id')->toString());

        $previousAssignee = $lead->assigned_to;

        $this->assignment->assignManual($lead, $sales, $request->user());

        $this->service->logEvent($lead, 'sales_assigned', [
            'assigned_to' => $sales->id,
            'method' => 'manual',
            'by' => $request->user()->id,
        ]);

        if ($previousAssignee && $previousAssignee !== $sales->id) {
            AuditLogger::log('lead.reassigned', 'lead', $lead->id, ['assigned_to' => $previousAssignee], ['assigned_to' => $sales->id]);
        }

        return ApiResponse::success(new LeadResource($lead->fresh(['customer', 'product', 'assignedUser'])));
    }

    public function notes(StoreLeadNoteRequest $request, Lead $lead): JsonResponse
    {
        Gate::forUser($request->user())->authorize('addNote', $lead);

        $note = $this->service->addNote($lead, $request->string('content')->toString(), $request->user());

        return ApiResponse::created([
            'id' => $note->id,
            'lead_id' => $note->lead_id,
            'content' => $note->content,
            'user_id' => $note->user_id,
            'created_at' => $note->created_at?->toIso8601String(),
        ]);
    }
}
