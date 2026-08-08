<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesDashboardController extends Controller
{
    /**
     * Sales dashboard (§50): "my leads" untuk role sales,
     * seluruh tenant untuk owner/manager.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Lead::query();

        if ($user->role === User::ROLE_SALES) {
            $query->where('assigned_to', $user->id);
        }

        $statusCounts = (clone $query)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $recent = (clone $query)
            ->with(['customer', 'product', 'assignedUser'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        return ApiResponse::success([
            'scope' => $user->role === User::ROLE_SALES ? 'mine' : 'all',
            'my_leads_total' => (clone $query)->count(),
            'new_leads' => (int) ($statusCounts['NEW'] ?? 0),
            'hot_leads' => (clone $query)->where('temperature', 'HOT')->count(),
            'won' => (int) ($statusCounts['WON'] ?? 0),
            'lost' => (int) ($statusCounts['LOST'] ?? 0),
            'pending_response' => (int) ($statusCounts['NEW'] ?? 0),
            'pipeline' => $statusCounts->map(fn ($total, $status) => [
                'status' => $status,
                'total' => (int) $total,
            ])->values(),
            'recent_leads' => $recent->map(fn (Lead $lead) => [
                'id' => $lead->id,
                'customer_name' => $lead->customer?->name,
                'customer_phone' => $lead->customer?->phone,
                'product_name' => $lead->product?->name,
                'status' => $lead->status,
                'temperature' => $lead->temperature,
                'score' => $lead->score,
                'source' => $lead->source,
                'created_at' => $lead->created_at?->toIso8601String(),
            ]),
        ]);
    }
}
