<?php

namespace App\Services\Lead;

use App\Models\CalculatorSession;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\LeadEvent;
use App\Models\Note;
use App\Models\Notification;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LeadService
{
    public function __construct(
        private readonly LeadScoringService $scoring,
        private readonly AssignmentService $assignment,
    ) {}

    /**
     * Full pipeline submit lead (§112): validate → normalize phone →
     * find/create customer → create lead → score → assign → log → notify.
     *
     * @param  array<string, mixed>  $data
     * @return array{lead: Lead, created: bool, assigned_to: array<string, mixed>|null}
     */
    public function createFromForm(array $data, ?Tenant $tenant = null): array
    {
        $tenant ??= app()->bound('currentTenant') ? app('currentTenant') : null;

        if (! $tenant) {
            throw new InvalidArgumentException('Tenant tidak terdeteksi.');
        }

        return DB::transaction(function () use ($data, $tenant) {
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
                'campaign_id' => $data['campaign_id'] ?? null,
                'status' => 'NEW',
                'provider_event_id' => $data['provider_event_id'] ?? null,
                'last_activity_at' => now(),
            ]);

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
            ]);

            $this->scoring->apply($lead, $context);

            $assigned = null;
            if (empty($data['skip_assignment'])) {
                $sales = $this->assignment->assignRoundRobin($lead);
                if ($sales) {
                    $this->logEvent($lead, 'sales_assigned', [
                        'assigned_to' => $sales->id,
                        'method' => 'round_robin',
                    ]);
                    $this->notifySales($lead, $sales);
                    $assigned = $this->assignedSummary($lead);
                }
            }

            return [
                'lead' => $lead->fresh(['customer', 'product', 'assignedUser']),
                'created' => true,
                'assigned_to' => $assigned,
            ];
        });
    }

    /**
     * Transisi status sesuai state machine (06-lead-state-machine.md).
     * WON/LOST terminal — tidak bisa keluar.
     */
    public function transition(Lead $lead, string $to, ?User $actor = null): Lead
    {
        $from = $lead->status;

        if ($from === $to) {
            return $lead;
        }

        $allowed = (array) config('tata.pipeline.transitions.'.$from, []);

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Transisi {$from} → {$to} tidak diizinkan."],
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

        return $lead->fresh();
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
