<?php

namespace App\Agents\Tools;

use App\Agents\Tools\Contracts\Tool;
use App\Models\Calculator;
use App\Services\Calculator\CalculatorService;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class CalculateTool implements Tool
{
    public function __construct(private readonly CalculatorService $calculators) {}

    public function name(): string
    {
        return 'calculate';
    }

    public function description(): string
    {
        return 'Jalankan kalkulator tenant (cicilan, simulasi harga, dll) secara deterministik. Semua angka financial dihasilkan oleh mesin ini — jangan pernah menghitung sendiri. Perlu calculator_id dari konteks percakapan.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'calculator_id' => ['type' => 'string', 'description' => 'id kalkulator yang tersedia untuk tenant (dari context/assembleContext)'],
                'inputs' => ['type' => 'object', 'description' => 'map key→nilai sesuai input kalkulator (dari percakapan customer)'],
                'product_id' => ['type' => 'string', 'description' => 'opsional: produk terkait'],
                'lead_id' => ['type' => 'string', 'description' => 'opsional: lead terkait percakapan'],
            ],
            'required' => ['calculator_id', 'inputs'],
        ];
    }

    public function execute(array $arguments): array
    {
        $tenantId = app()->bound('currentTenant') ? app('currentTenant')->id : null;

        $calculator = Calculator::query()->find(Arr::get($arguments, 'calculator_id'));

        if (! $calculator || $calculator->status !== 'active'
            || ($tenantId && $calculator->tenant_id !== $tenantId)) {
            return ['found' => false, 'reason' => 'Kalkulator tidak tersedia untuk percakapan ini.'];
        }

        try {
            $result = $this->calculators->run(
                $calculator,
                (array) (Arr::get($arguments, 'inputs') ?? []),
                productId: Arr::get($arguments, 'product_id') ?: null,
                leadId: Arr::get($arguments, 'lead_id') ?: null,
            );
        } catch (ValidationException $e) {
            return [
                'found' => false,
                'validation_errors' => $e->errors(),
                'reason' => 'Input kalkulator tidak lengkap atau tidak valid — tanyakan nilai yang kurang ke customer.',
            ];
        }

        return [
            'found' => true,
            'calculator_id' => $calculator->id,
            'calculator_name' => $calculator->name,
            'session_id' => $result['session_id'],
            'outputs' => $result['outputs'],
        ];
    }
}
