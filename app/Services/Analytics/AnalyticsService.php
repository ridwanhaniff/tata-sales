<?php

namespace App\Services\Analytics;

use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    public function summary(Tenant $tenant): array
    {
        $leads = Lead::query()
            ->where('tenant_id', $tenant->id)
            ->selectRaw('
                count(*) as total,
                count(*) FILTER (WHERE status = ?) as won_total,
                count(*) FILTER (WHERE temperature = ?) as hot_total,
                count(*) FILTER (WHERE status NOT IN (?, ?)) as open_total,
                COALESCE(SUM(estimated_value) FILTER (WHERE status NOT IN (?, ?)), 0) as revenue_potential
            ', ['WON', 'HOT', 'WON', 'LOST', 'WON', 'LOST'])
            ->first();

        $calculatorCompleted = LeadEvent::query()
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'calculator_completed')
            ->distinct('lead_id')
            ->count('lead_id');

        $whatsappClicks = CampaignEvent::query()
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'whatsapp_click')
            ->count();

        return [
            'total_leads' => (int) $leads->total,
            'revenue_potential' => (float) $leads->revenue_potential,
            'hot_leads' => (int) $leads->hot_total,
            'open_leads' => (int) $leads->open_total,
            'conversion_rate' => $leads->total > 0 ? round(($leads->won_total / $leads->total) * 100, 1) : 0.0,
            'calculator_completion' => (int) $calculatorCompleted,
            'calculator_completion_rate' => $leads->total > 0
                ? round(($calculatorCompleted / $leads->total) * 100, 1)
                : 0.0,
            'whatsapp_clicks' => (int) $whatsappClicks,
        ];
    }

    public function responseTime(Tenant $tenant): array
    {
        $result = DB::table('lead_events as le')
            ->join('leads as l', 'l.id', '=', 'le.lead_id')
            ->where('l.tenant_id', $tenant->id)
            ->where('le.event_type', 'contacted')
            ->selectRaw('count(*) as contacted_total, round(avg(extract(epoch from (le.occurred_at - l.created_at)))) as avg_seconds')
            ->first();

        $avgSeconds = $result->avg_seconds !== null ? (int) $result->avg_seconds : null;

        return [
            'contacted_total' => (int) $result->contacted_total,
            'avg_seconds' => $avgSeconds,
            'avg_minutes' => $avgSeconds !== null ? round($avgSeconds / 60, 1) : null,
        ];
    }

    public function funnel(Tenant $tenant): array
    {
        $productViews = CampaignEvent::query()
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'product_view')
            ->count();

        $formCompletes = CampaignEvent::query()
            ->where('tenant_id', $tenant->id)
            ->where('event_type', 'form_complete')
            ->count();

        $leads = Lead::query()->where('tenant_id', $tenant->id)->count();
        $won = Lead::query()->where('tenant_id', $tenant->id)->where('status', 'WON')->count();

        return [
            'product_views' => (int) $productViews,
            'form_completes' => (int) $formCompletes,
            'leads_created' => (int) $leads,
            'leads_won' => (int) $won,
        ];
    }

    public function topProducts(Tenant $tenant, int $limit = 5): array
    {
        $rows = Lead::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('product_id')
            ->select('product_id')
            ->selectRaw('count(*) as total, sum(estimated_value) as potential')
            ->groupBy('product_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        $products = Product::query()
            ->whereIn('id', $rows->pluck('product_id'))
            ->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'product_id' => $row->product_id,
            'name' => $products[$row->product_id] ?? 'Produk dihapus',
            'leads' => (int) $row->total,
            'revenue_potential' => (float) ($row->potential ?? 0),
        ])->values()->all();
    }

    public function topCampaigns(Tenant $tenant, int $limit = 5): array
    {
        $rows = Lead::query()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('campaign_id')
            ->select('campaign_id')
            ->selectRaw('count(*) as total')
            ->groupBy('campaign_id')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        $campaigns = Campaign::query()
            ->whereIn('id', $rows->pluck('campaign_id'))
            ->pluck('name', 'id');

        return $rows->map(fn ($row) => [
            'campaign_id' => $row->campaign_id,
            'name' => $campaigns[$row->campaign_id] ?? 'Kampanye dihapus',
            'leads' => (int) $row->total,
        ])->values()->all();
    }
}
