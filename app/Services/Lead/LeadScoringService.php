<?php

namespace App\Services\Lead;

use App\Models\Lead;
use App\Models\LeadScore;
use App\Models\Tenant;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class LeadScoringService
{
    /**
     * Skor lead rule-based (§15). Bobot dari tenants.settings.scoring_weights,
     * fallback ke config('tata.scoring.weights').
     *
     * @param  array<string, mixed>  $context  data kontekstual lead (campaign, calculator, dsb.)
     * @return array{score: int, temperature: string}
     */
    public function score(Lead $lead, array $context = []): array
    {
        $weights = $this->weightsFor($lead->tenant_id);

        $points = 0;
        $points += Arr::get($weights, 'has_email', 0) * (int) (bool) $lead->customer?->email;
        $points += Arr::get($weights, 'has_location', 0) * (int) (bool) $lead->customer?->location;
        $points += Arr::get($weights, 'has_product', 0) * (int) (bool) $lead->product_id;
        $points += Arr::get($weights, 'has_variant', 0) * (int) (bool) $lead->variant_id;
        $points += Arr::get($weights, 'calculator_completed', 0) * (int) (bool) ($context['calculator_completed'] ?? false);
        $points += Arr::get($weights, 'consent_marketing', 0) * (int) (bool) ($lead->customer?->consent_marketing ?? false);
        $points += Arr::get($weights, 'has_campaign', 0) * (int) (bool) $lead->campaign_id;
        $points += Arr::get($weights, 'source_whatsapp', 0) * (int) ($lead->source === 'whatsapp');
        $points += Arr::get($weights, 'source_chat', 0) * (int) ($lead->source === 'chat');
        $points += Arr::get($weights, 'source_form', 0) * (int) ($lead->source === 'form');

        $score = min($points, (int) config('tata.scoring.max_score', 100));

        return [
            'score' => $score,
            'temperature' => $this->temperature($score),
        ];
    }

    /**
     * Catat hasil scoring ke lead_scores dan update lead.
     *
     * @return array{score: int, temperature: string}
     */
    public function apply(Lead $lead, array $context = []): array
    {
        $result = $this->score($lead, $context);

        $lastScore = $lead->scores()->latest('created_at')->first();
        $resultingScore = $lastScore ? $lastScore->resulting_score : 0;

        LeadScore::create([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'event_type' => 'lead_created',
            'points' => $result['score'] - $resultingScore,
            'resulting_score' => $result['score'],
        ]);

        $lead->forceFill([
            'score' => $result['score'],
            'temperature' => $result['temperature'],
        ])->save();

        return $result;
    }

    public function temperature(int $score): string
    {
        return match (true) {
            $score >= 60 => 'HOT',
            $score >= 30 => 'WARM',
            default => 'COLD',
        };
    }

    /**
     * @return array<string, int>
     */
    private function weightsFor(string $tenantId): array
    {
        $defaults = (array) config('tata.scoring.weights', []);

        $tenant = Tenant::query()->find($tenantId);

        return $tenant ? array_merge($defaults, (array) ($tenant->settings['scoring_weights'] ?? [])) : $defaults;
    }

    public function breakdown(Lead $lead): Collection
    {
        return $lead->scores()->orderBy('created_at')->get();
    }
}
