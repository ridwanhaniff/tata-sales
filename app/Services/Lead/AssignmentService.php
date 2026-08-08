<?php

namespace App\Services\Lead;

use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
    /**
     * Assign lead ke sales via round-robin (§26):
     * sales dengan jumlah lead aktif (non-terminal) paling sedikit,
     * tie-break oleh yang paling lama tidak menerima assignment.
     */
    public function assignRoundRobin(Lead $lead): ?User
    {
        $candidates = $this->roundRobinCandidates($lead->tenant_id);

        if ($candidates->isEmpty()) {
            return null;
        }

        $sales = $candidates->first();

        $this->assign($lead, $sales, null, 'round_robin');

        return $sales;
    }

    public function assignManual(Lead $lead, User $sales, ?User $actor): User
    {
        if ($sales->tenant_id !== $lead->tenant_id) {
            throw new \InvalidArgumentException('Sales tidak berada di tenant yang sama.');
        }

        $this->assign($lead, $sales, $actor, 'manual');

        return $sales;
    }

    private function assign(Lead $lead, User $sales, ?User $actor, string $method): void
    {
        DB::transaction(function () use ($lead, $sales, $actor, $method) {
            $lead->assignments()
                ->whereNull('unassigned_at')
                ->update(['unassigned_at' => now()]);

            LeadAssignment::create([
                'tenant_id' => $lead->tenant_id,
                'lead_id' => $lead->id,
                'assigned_to' => $sales->id,
                'assigned_by' => $actor?->id,
                'method' => $method,
            ]);

            $lead->forceFill([
                'assigned_to' => $sales->id,
                'last_activity_at' => now(),
            ])->save();
        });
    }

    private function roundRobinCandidates(string $tenantId)
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->where('role', User::ROLE_SALES)
            ->where('status', 'active')
            ->withCount(['assignedLeads' => function ($query) {
                $query->whereNotIn('status', ['WON', 'LOST']);
            }])
            ->orderBy('assigned_leads_count')
            ->orderByRaw('(SELECT MAX(assigned_at) FROM lead_assignments la WHERE la.assigned_to = users.id) ASC NULLS FIRST')
            ->limit(1)
            ->get();
    }
}
