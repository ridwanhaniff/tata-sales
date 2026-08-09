<?php

namespace App\Services\Lead;

use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AssignmentService
{
    public const METHOD_ROUND_ROBIN = 'round_robin';

    public const METHOD_PRODUCT = 'product';

    public const METHOD_LOCATION = 'location';

    public const METHOD_WORKLOAD = 'workload';

    public const METHOD_MANUAL = 'manual';

    /**
     * Assign lead sesuai strategi per tenant (§26).
     * Strategi dikonfigurasi di `tenants.settings.assignment.method`
     * (round_robin | product | location | workload).
     *
     * `product`/`location` mencari kandidat dari sales_teams; kalau tidak ada
     * kandidat yang cocok, fallback ke round_robin (method pada hasil mencerminkan
     * strategi yang BENAR-BENAR dieksekusi).
     *
     * @return array{sales: ?User, method: string}
     */
    public function assign(Lead $lead): array
    {
        return match ($this->methodFor($lead->tenant_id)) {
            self::METHOD_PRODUCT => $this->assignByProduct($lead),
            self::METHOD_LOCATION => $this->assignByLocation($lead),
            self::METHOD_WORKLOAD => $this->assignByWorkload($lead),
            default => $this->assignRoundRobin($lead),
        };
    }

    public function assignRoundRobin(Lead $lead): array
    {
        $sales = $this->leastLoaded($this->activeSalesQuery($lead->tenant_id), tieBreak: true);

        if ($sales) {
            $this->assignTo($lead, $sales, method: self::METHOD_ROUND_ROBIN);
        }

        return ['sales' => $sales, 'method' => self::METHOD_ROUND_ROBIN];
    }

    public function assignManual(Lead $lead, User $sales, ?User $actor): User
    {
        if ($sales->tenant_id !== $lead->tenant_id && ! $sales->isSuperAdmin()) {
            throw new \InvalidArgumentException('Sales tidak berada di tenant yang sama.');
        }

        $this->assignTo($lead, $sales, $actor, self::METHOD_MANUAL);

        return $sales;
    }

    /**
     * @return array{sales: ?User, method: string}
     */
    private function assignByProduct(Lead $lead): array
    {
        $categoryId = $lead->product?->category_id;

        if (! $categoryId) {
            return $this->assignRoundRobin($lead);
        }

        $sales = $this->leastLoaded(
            $this->teamMembersQuery($lead->tenant_id)
                ->where('sales_teams.product_category_id', $categoryId)
        );

        if (! $sales) {
            return $this->assignRoundRobin($lead);
        }

        $this->assignTo($lead, $sales, method: self::METHOD_PRODUCT);

        return ['sales' => $sales, 'method' => self::METHOD_PRODUCT];
    }

    /**
     * @return array{sales: ?User, method: string}
     */
    private function assignByLocation(Lead $lead): array
    {
        $location = $lead->customer?->location;

        if (! $location) {
            return $this->assignRoundRobin($lead);
        }

        $sales = $this->leastLoaded(
            $this->teamMembersQuery($lead->tenant_id)
                ->whereNotNull('sales_teams.region')
                ->where('sales_teams.region', 'ilike', '%'.$location.'%')
        );

        if (! $sales) {
            return $this->assignRoundRobin($lead);
        }

        $this->assignTo($lead, $sales, method: self::METHOD_LOCATION);

        return ['sales' => $sales, 'method' => self::METHOD_LOCATION];
    }

    /**
     * @return array{sales: ?User, method: string}
     */
    private function assignByWorkload(Lead $lead): array
    {
        $sales = $this->leastLoaded($this->activeSalesQuery($lead->tenant_id), tieBreak: false);

        if ($sales) {
            $this->assignTo($lead, $sales, method: self::METHOD_WORKLOAD);
        }

        return ['sales' => $sales, 'method' => self::METHOD_WORKLOAD];
    }

    /**
     * Kandidat sales dari keanggotaan sales_teams (join sales_team_members).
     */
    private function teamMembersQuery(string $tenantId): Builder
    {
        return User::query()
            ->join('sales_team_members', 'sales_team_members.user_id', '=', 'users.id')
            ->join('sales_teams', 'sales_teams.id', '=', 'sales_team_members.sales_team_id')
            ->where('sales_teams.tenant_id', $tenantId)
            ->select('users.*');
    }

    private function activeSalesQuery(string $tenantId): Builder
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->where('role', User::ROLE_SALES);
    }

    private function leastLoaded(Builder $query, bool $tieBreak = true): ?User
    {
        $query->withCount(['assignedLeads' => function (Builder $q) {
            $q->whereNotIn('status', ['WON', 'LOST']);
        }]);

        if ($tieBreak) {
            $query->orderByRaw('(SELECT MAX(la.assigned_at) FROM lead_assignments la WHERE la.assigned_to = users.id) ASC NULLS FIRST');
        }

        return $query->orderBy('assigned_leads_count')
            ->orderBy('users.id')
            ->first();
    }

    private function methodFor(string $tenantId): string
    {
        $tenant = Tenant::query()
            ->withoutGlobalScope('tenant')
            ->find($tenantId);

        if (! $tenant) {
            return self::METHOD_ROUND_ROBIN;
        }

        $method = $tenant->settings['assignment']['method'] ?? self::METHOD_ROUND_ROBIN;

        return in_array($method, [self::METHOD_PRODUCT, self::METHOD_LOCATION, self::METHOD_WORKLOAD], true)
            ? $method
            : self::METHOD_ROUND_ROBIN;
    }

    private function assignTo(Lead $lead, User $sales, ?User $actor = null, ?string $method = null): void
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
                'method' => $method ?? self::METHOD_ROUND_ROBIN,
            ]);

            $lead->forceFill([
                'assigned_to' => $sales->id,
                'last_activity_at' => now(),
            ])->save();
        });
    }
}
