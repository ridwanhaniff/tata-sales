<?php

namespace App\Agents\Tools;

use App\Agents\Tools\Contracts\Tool;
use App\Models\Lead;
use App\Services\Lead\AssignmentService;
use App\Services\Lead\LeadService;
use Illuminate\Support\Arr;

/**
 * assign_sales (§5 roster + §3 chain: create_lead → assign_sales).
 * Lead yang sudah di-assign tidak di-assign ulang; tanpa sales aktif
 * tool menolak. Metode opsional (round_robin/workload/product/location);
 * kosong → strategi tenant (AssignmentService::assign).
 */
class AssignSalesTool implements Tool
{
    public const METHODS = [
        AssignmentService::METHOD_ROUND_ROBIN,
        AssignmentService::METHOD_WORKLOAD,
        AssignmentService::METHOD_PRODUCT,
        AssignmentService::METHOD_LOCATION,
    ];

    public function __construct(
        private readonly AssignmentService $assignment,
        private readonly LeadService $leads,
    ) {}

    public function name(): string
    {
        return 'assign_sales';
    }

    public function description(): string
    {
        return 'Assign lead ke sales aktif sesuai strategi tenant (atau metode yang diminta). Hanya untuk lead yang belum di-assign; bila tidak ada sales tersedia, tool menolak.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lead_id' => ['type' => 'string'],
                'method' => [
                    'type' => 'string',
                    'enum' => self::METHODS,
                    'description' => 'opsional: round_robin | workload | product | location; kosong = strategi tenant',
                ],
            ],
            'required' => ['lead_id'],
        ];
    }

    public function execute(array $arguments): array
    {
        $lead = Lead::query()->find(Arr::get($arguments, 'lead_id'));

        if (! $lead) {
            return ['done' => false, 'reason' => 'Lead tidak ditemukan.'];
        }

        if ($lead->assigned_to) {
            return [
                'done' => false,
                'reason' => 'Lead sudah di-assign ke sales.',
                'assigned_to' => $this->salesSummary($lead),
            ];
        }

        $method = Arr::get($arguments, 'method');

        $result = is_string($method) && in_array($method, self::METHODS, true)
            ? $this->assignment->assignByMethod($lead, $method)
            : $this->assignment->assign($lead);

        $sales = $result['sales'];

        if (! $sales) {
            return ['done' => false, 'reason' => 'Tidak ada sales aktif yang tersedia.', 'method' => $result['method']];
        }

        $this->leads->notifySales($lead, $sales);
        $this->leads->logEvent($lead, 'sales_assigned', [
            'assigned_to' => $sales->id,
            'method' => $result['method'],
        ]);

        return [
            'done' => true,
            'assigned_to' => [
                'id' => $sales->id,
                'name' => $sales->name,
            ],
            'method' => $result['method'],
        ];
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private function salesSummary(Lead $lead): ?array
    {
        $sales = $lead->assignedUser;

        return $sales ? ['id' => $sales->id, 'name' => $sales->name] : null;
    }
}
