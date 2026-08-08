<?php

namespace App\Services\Lead;

use App\Models\Campaign;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class AttributionService
{
    /**
     * Bandingkan UTM dari request dengan campaigns.utm_campaign milik tenant;
     * kembalikan campaign yang cocok (kalau ada).
     */
    public function matchCampaign(?string $tenantId, array $utm): ?Campaign
    {
        $utmCampaign = Arr::get($utm, 'utm_campaign');

        if (! $utmCampaign) {
            return null;
        }

        return Campaign::query()
            ->where('tenant_id', $tenantId)
            ->where('utm_campaign', $utmCampaign)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Struktur atribusi yang disimpan di lead_events/campaign_events.
     *
     * @return array<string, mixed>
     */
    public function sanitize(array $utm, ?string $referrer = null, ?string $landingPage = null): array
    {
        return [
            'utm' => Arr::only($utm, ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term']),
            'referrer' => $referrer,
            'landing_page' => $landingPage,
        ];
    }

    public function referrerFrom(Request $request): ?string
    {
        return $request->header('Referer') ?: $request->input('referrer');
    }

    public function landingPageFrom(Request $request): ?string
    {
        $path = $request->input('landing_page');

        if ($path) {
            return $path;
        }

        $referrer = $this->referrerFrom($request);

        if ($referrer && str_contains($referrer, '/l/')) {
            return '/l/'.$this->lastPathSegment($referrer);
        }

        return null;
    }

    private function lastPathSegment(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        return basename(trim($path, '/'));
    }
}
