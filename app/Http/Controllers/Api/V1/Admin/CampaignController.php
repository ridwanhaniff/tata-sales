<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Campaign\StoreCampaignRequest;
use App\Http\Requests\Campaign\UpdateCampaignRequest;
use App\Http\Resources\CampaignResource;
use App\Models\Campaign;
use App\Models\CampaignSource;
use App\Support\ApiResponse;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CampaignController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $campaigns = Campaign::query()
            ->with('sources')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'ilike', '%'.$request->string('search').'%'))
            ->orderByDesc('created_at')
            ->paginate(min($request->integer('per_page', 20), 100));

        return ApiResponse::paginated($campaigns, CampaignResource::class);
    }

    public function store(StoreCampaignRequest $request): JsonResponse
    {
        $tenantId = $request->attributes->get('tenant')?->id;

        $campaign = DB::transaction(function () use ($request, $tenantId) {
            $campaign = Campaign::create([
                'tenant_id' => $tenantId,
                'name' => $request->string('name')->toString(),
                'utm_campaign' => $request->input('utm_campaign'),
                'status' => $request->input('status', 'active'),
                'budget' => $request->input('budget'),
                'starts_at' => $request->input('starts_at'),
                'ends_at' => $request->input('ends_at'),
            ]);

            $this->syncSources($campaign, (array) $request->input('sources', []));

            AuditLogger::log('campaign.created', 'campaign', $campaign->id, [], $request->validated());

            return $campaign;
        });

        return ApiResponse::created(new CampaignResource($campaign->load('sources')));
    }

    public function show(Campaign $campaign): JsonResponse
    {
        return ApiResponse::success(new CampaignResource($campaign->load('sources')));
    }

    public function update(UpdateCampaignRequest $request, Campaign $campaign): JsonResponse
    {
        $before = Arr::only($campaign->getAttributes(), ['name', 'status', 'budget', 'starts_at', 'ends_at']);

        $campaign->update([
            'name' => $request->input('name', $campaign->name),
            'utm_campaign' => $request->input('utm_campaign', $campaign->utm_campaign),
            'status' => $request->input('status', $campaign->status),
            'budget' => $request->input('budget', $campaign->budget),
            'starts_at' => $request->input('starts_at', $campaign->starts_at),
            'ends_at' => $request->input('ends_at', $campaign->ends_at),
        ]);

        if ($request->has('sources')) {
            $this->syncSources($campaign, (array) $request->input('sources'));
        }

        AuditLogger::log('campaign.updated', 'campaign', $campaign->id, $before, Arr::only(
            $campaign->fresh()->getAttributes(),
            ['name', 'status', 'budget', 'starts_at', 'ends_at']
        ));

        return ApiResponse::success(new CampaignResource($campaign->fresh(['sources'])));
    }

    public function destroy(Campaign $campaign): JsonResponse
    {
        $id = $campaign->id;
        $campaign->delete();

        AuditLogger::log('campaign.deleted', 'campaign', $id);

        return ApiResponse::noContent();
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     */
    private function syncSources(Campaign $campaign, array $sources): void
    {
        CampaignSource::query()->where('campaign_id', $campaign->id)->delete();

        foreach (array_values(array_filter($sources)) as $source) {
            CampaignSource::create([
                'tenant_id' => $campaign->tenant_id,
                'campaign_id' => $campaign->id,
                'utm_source' => $source['utm_source'] ?? null,
                'utm_medium' => $source['utm_medium'] ?? null,
                'utm_content' => $source['utm_content'] ?? null,
                'utm_term' => $source['utm_term'] ?? null,
                'referrer' => $source['referrer'] ?? null,
                'landing_page_id' => $source['landing_page_id'] ?? null,
            ]);
        }
    }
}
