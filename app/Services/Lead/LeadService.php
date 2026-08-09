<?php

namespace App\Services\Lead;

use App\Models\CalculatorSession;
use App\Models\Campaign;
use App\Models\CampaignEvent;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Note;
use App\Models\Notification;
use App\Models\PipelineStage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FollowUp\FollowUpService;
use App\Services\Webhook\OutboundWebhookService;
use App\Services\Workflow\WorkflowEngine;
use App\Support\AuditLogger;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LeadService
{
    public function __construct(
        private readonly LeadScoringService $scoring,
        private readonly AssignmentService $assignment,
        private readonly AttributionService $attribution,
        private readonly WorkflowEngine $workflow,
        private readonly FollowUpService $followUps,
        private readonly OutboundWebhookService $webhooks,
    ) {}

    /**
     * Full pipeline submit lead (§112): validate → normalize phone →
     * find/create customer → create lead → score → assign → log → notify.
     *
     * @param  array<string, mixed>  $data
     * @return array{lead: Lead, created: bool, assigned_to: array<string, mixed>|null}
     */
    public function createFromForm(array $data, ?Tenant $tenant = null, ?Request $request = null): array
    {
        $tenant ??= app()->bound('currentTenant') ? app('currentTenant') : null;

        if (! $tenant) {
            throw new InvalidArgumentException('Tenant tidak terdeteksi.');
        }

        return DB::transaction(function () use ($data, $tenant, $request) {
            $customerData = Arr::get($data, 'customer', []);

            try {
                $phone = PhoneNormalizer::normalize((string) ($customerData['phone'] ?? ''));
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages([
                    'customer.phone' => [$e->getMessage()],
                ]);
            }

            $existingLead = $this->findByProviderEvent($tenant->id, $data['provider_event_id'] ?? null);
            if ($existingLead) {
                return ['lead' => $existingLead, 'created' => false, 'assigned_to' => $this->assignedSummary($existingLead)];
            }

            $attribution = $this->resolveAttribution($tenant, $data, $request);

            $campaignId = Arr::get($data, 'campaign_id') ?? $attribution['campaign']?->id;

            $customer = Customer::query()
                ->where('phone', $phone)
                ->first();

            if (! $customer) {
                $customer = Customer::create([
                    'tenant_id' => $tenant->id,
                    'name' => $customerData['name'] ?? null,
                    'phone' => $phone,
                    'email' => $customerData['email'] ?? null,
                    'source' => $data['source'] ?? 'form',
                    'consent_marketing' => (bool) ($data['consent_marketing'] ?? false),
                    'consent_at' => ($data['consent_marketing'] ?? false) ? now() : null,
                ]);
            } elseif (($data['consent_marketing'] ?? false) && ! $customer->consent_marketing) {
                $customer->forceFill([
                    'consent_marketing' => true,
                    'consent_at' => now(),
                ])->save();
            }

            $lead = Lead::create([
                'tenant_id' => $tenant->id,
                'customer_id' => $customer->id,
                'product_id' => $data['product_id'] ?? null,
                'variant_id' => $data['variant_id'] ?? null,
                'source' => $data['source'] ?? 'form',
                'campaign_id' => $campaignId,
                'status' => 'NEW',
                'provider_event_id' => $data['provider_event_id'] ?? null,
                'last_activity_at' => now(),
            ]);

            $this->recordAttribution($tenant, $lead, $attribution);

            $context = ['calculator_completed' => false];

            if (! empty($data['calculator_session_id'])) {
                $session = CalculatorSession::query()->find($data['calculator_session_id']);
                if ($session) {
                    $session->forceFill(['lead_id' => $lead->id, 'customer_id' => $customer->id])->save();
                    $context['calculator_completed'] = true;
                    $this->logEvent($lead, 'calculator_completed', [
                        'session_id' => $session->id,
                        'calculator_id' => $session->calculator_id,
                    ]);
                }
            }

            $this->logEvent($lead, 'lead_created', [
                'source' => $lead->source,
                'product_id' => $lead->product_id,
                'campaign_id' => $lead->campaign_id,
                'attribution' => $attribution['data'],
            ]);

            $this->logEvent($lead, 'lead_created', [
                'source' => $lead->source,
                'product_id' => $lead->product_id,
                'campaign_id' => $lead->campaign_id,
                'attribution' => $attribution['data'],
            ]);

            $this->scoring->apply($lead, $context);

            $assigned = null;
            if (empty($data['skip_assignment'])) {
                $result = $this->assignment->assign($lead);
                $sales = $result['sales'];
                if ($sales) {
                    $this->logEvent($lead, 'sales_assigned', [
                        'assigned_to' => $sales->id,
                        'method' => $result['method'],
                    ]);
                    $this->notifySales($lead, $sales);
                    $assigned = $this->assignedSummary($lead);
                }
            }

            $fresh = $lead->fresh(['customer', 'product', 'assignedUser']);
            $this->workflow->trigger('lead_created', $fresh);
            $this->followUps->scheduleFor($fresh, 'lead_created');

            $this->webhooks->dispatch($tenant, 'lead.created', [
                'lead_id' => $lead->id,
                'status' => $lead->status,
                'product_id' => $lead->product_id,
                'score' => $lead->score,
                'customer_phone' => $customer->phone,
            ]);

            return [
                'lead' => $fresh,
                'created' => true,
                'assigned_to' => $assigned,
            ];
        });
    }

    /**
     * Transisi status sesuai state machine (06-lead-state-machine.md).
     * WON/LOST terminal — tidak bisa keluar. Target status wajib ada di
     * pipeline_stages tenant (custom pipeline §27), transisi bisa di-override
     * lewat tenants.settings.pipeline.transitions.
     */
    public function transition(Lead $lead, string $to, ?User $actor = null): Lead
    {
        $from = $lead->status;

        if ($from === $to) {
            return $lead;
        }

        $tenant = Tenant::query()
            ->withoutGlobalScope('tenant')
            ->find($lead->tenant_id);

        $stage = PipelineStage::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $lead->tenant_id)
            ->where('key', $to)
            ->first();

        if (! $stage) {
            throw ValidationException::withMessages([
                'status' => ["Status {$to} tidak ada di pipeline tenant ini."],
            ]);
        }

        // WON/LOST selalu terminal — override tenant tidak bisa membuka jalur keluar.
        if (in_array($from, ['WON', 'LOST'], true)) {
            throw ValidationException::withMessages([
                'status' => ["Transisi {$from} → {$to} tidak diizinkan — {$from} adalah status terminal."],
            ]);
        }

        $allowed = (array) config('tata.pipeline.transitions.'.$from, []);

        $override = $tenant?->settings['pipeline']['transitions'] ?? null;
        if (is_array($override) && isset($override[$from]) && is_array($override[$from])) {
            $allowed = $override[$from];
        }

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Transisi {$from} → {$to} tidak diizinkan."],
            ]);
        }

        if ($from === 'NEW' && $to === 'CONTACTED' && ! $lead->assigned_to) {
            throw ValidationException::withMessages([
                'status' => ['Lead harus di-assign ke sales sebelum ditandai CONTACTED.'],
            ]);
        }

        if ($from === 'NEW' && $to === 'LOST' && $actor && $actor->role === 'sales') {
            throw ValidationException::withMessages([
                'status' => ['Hanya owner/manager yang bisa menandai lead baru sebagai LOST.'],
            ]);
        }

        $lead->forceFill([
            'status' => $to,
            'last_activity_at' => now(),
        ])->save();

        $this->logEvent($lead, strtolower($to), [
            'from' => $from,
            'by' => $actor?->id,
        ]);

        AuditLogger::log('lead.status_changed', 'lead', $lead->id, ['status' => $from], ['status' => $to]);

        $fresh = $lead->fresh(['customer', 'product', 'assignedUser']);
        $this->workflow->trigger('lead_'.strtolower($to), $fresh);
        $this->followUps->scheduleFor($fresh, 'lead_'.strtolower($to));

        if (in_array($to, ['WON', 'LOST'], true) && $tenant) {
            $this->webhooks->dispatch($tenant, 'deal.'.strtolower($to), [
                'lead_id' => $lead->id,
                'status' => $to,
                'customer_id' => $lead->customer_id,
                'product_id' => $lead->product_id,
                'score' => $lead->score,
            ]);
        } elseif ($tenant) {
            $this->webhooks->dispatch($tenant, 'lead.updated', [
                'lead_id' => $lead->id,
                'from' => $from,
                'to' => $to,
                'customer_id' => $lead->customer_id,
                'product_id' => $lead->product_id,
                'score' => $lead->score,
            ]);
        }

        return $fresh;
    }

    public function addNote(Lead $lead, string $content, ?User $actor = null): Note
    {
        $note = Note::create([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'customer_id' => $lead->customer_id,
            'user_id' => $actor?->id,
            'content' => $content,
        ]);

        $lead->forceFill(['last_activity_at' => now()])->save();

        $this->logEvent($lead, 'note_added', [
            'note_id' => $note->id,
            'by' => $actor?->id,
        ]);

        return $note;
    }

    public function logEvent(Lead $lead, string $eventType, array $eventData = []): LeadEvent
    {
        return LeadEvent::create([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'event_type' => $eventType,
            'event_data' => $eventData,
        ]);
    }

    private function findByProviderEvent(string $tenantId, ?string $providerEventId): ?Lead
    {
        if (! $providerEventId) {
            return null;
        }

        return Lead::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('provider_event_id', $providerEventId)
            ->first();
    }

    /**
     * @return array{campaign: ?Campaign, data: array<string, mixed>}
     */
    private function resolveAttribution(Tenant $tenant, array $data, ?Request $request): array
    {
        if (! $this->attribution) {
            return ['campaign' => null, 'data' => []];
        }

        $referrer = $request ? $this->attribution->referrerFrom($request) : null;
        $landingPage = $data['landing_page'] ?? ($request ? $this->attribution->landingPageFrom($request) : null);

        $campaign = $this->attribution->matchCampaign(
            $tenant->id,
            (array) ($data['utm'] ?? [])
        );

        return [
            'campaign' => $campaign,
            'data' => $this->attribution->sanitize((array) ($data['utm'] ?? []), $referrer, $landingPage),
        ];
    }

    /**
     * @param  array{campaign: ?Campaign, data: array<string, mixed>}  $attribution
     */
    private function recordAttribution(Tenant $tenant, Lead $lead, array $attribution): void
    {
        $data = $attribution['data'];

        if ($data['utm'] === [] && $data['referrer'] === null && $data['landing_page'] === null) {
            return;
        }

        CampaignEvent::create([
            'tenant_id' => $tenant->id,
            'campaign_id' => $attribution['campaign']?->id,
            'event_type' => 'form_complete',
            'event_data' => [
                ...$data,
                'lead_id' => $lead->id,
            ],
            'occurred_at' => now(),
        ]);
    }

    private function notifySales(Lead $lead, User $sales): void
    {
        Notification::create([
            'tenant_id' => $lead->tenant_id,
            'user_id' => $sales->id,
            'channel' => 'dashboard',
            'type' => 'new_lead',
            'title' => 'Lead baru menunggu respons',
            'body' => ($lead->customer?->name ?? 'Customer baru').' • '.
                ($lead->product?->name ?? 'produk tidak disebutkan').' • score '.$lead->score,
            'data' => [
                'lead_id' => $lead->id,
                'score' => $lead->score,
                'temperature' => $lead->temperature,
            ],
            'sent_at' => now(),
        ]);
    }

    private function assignedSummary(Lead $lead): ?array
    {
        $sales = $lead->assignedUser;

        if (! $sales) {
            return null;
        }

        return [
            'id' => $sales->id,
            'name' => $sales->name,
        ];
    }
}
