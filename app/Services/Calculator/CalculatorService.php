<?php

namespace App\Services\Calculator;

use App\Models\Calculator;
use App\Models\CalculatorInput;
use App\Models\CalculatorOutput;
use App\Models\CalculatorRule;
use App\Models\CalculatorSession;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class CalculatorService
{
    public function create(array $data, ?string $tenantId = null): Calculator
    {
        return DB::transaction(function () use ($data, $tenantId) {
            $calculator = Calculator::create([
                ...Arr::only($data, ['name', 'type']),
                'tenant_id' => $tenantId,
                'status' => $data['status'] ?? 'active',
            ]);

            $this->syncDefinition($calculator, $data);

            return $calculator->fresh();
        });
    }

    public function update(Calculator $calculator, array $data): Calculator
    {
        return DB::transaction(function () use ($calculator, $data) {
            $calculator->update(Arr::only($data, ['name', 'type', 'status']));

            if (array_key_exists('inputs', $data) || array_key_exists('rules', $data) || array_key_exists('outputs', $data)) {
                $this->syncDefinition($calculator, $data);
            }

            return $calculator->fresh();
        });
    }

    private function syncDefinition(Calculator $calculator, array $data): void
    {
        CalculatorInput::query()->where('calculator_id', $calculator->id)->delete();
        CalculatorRule::query()->where('calculator_id', $calculator->id)->delete();
        CalculatorOutput::query()->where('calculator_id', $calculator->id)->delete();

        foreach ($data['inputs'] ?? [] as $index => $input) {
            CalculatorInput::create([
                'tenant_id' => $calculator->tenant_id,
                'calculator_id' => $calculator->id,
                'key' => $input['key'],
                'label' => $input['label'],
                'data_type' => $input['data_type'],
                'min_value' => $input['min_value'] ?? null,
                'max_value' => $input['max_value'] ?? null,
                'options' => $input['options'] ?? null,
                'is_required' => (bool) ($input['is_required'] ?? true),
                'sort_order' => $input['sort_order'] ?? $index,
            ]);
        }

        foreach ($data['rules'] ?? [] as $index => $rule) {
            CalculatorRule::create([
                'tenant_id' => $calculator->tenant_id,
                'calculator_id' => $calculator->id,
                'formula' => $rule['formula'],
                'rounding_policy' => $rule['rounding_policy'] ?? 'round',
                'sort_order' => $rule['sort_order'] ?? $index,
            ]);
        }

        foreach ($data['outputs'] ?? [] as $index => $output) {
            CalculatorOutput::create([
                'tenant_id' => $calculator->tenant_id,
                'calculator_id' => $calculator->id,
                'key' => $output['key'],
                'label' => $output['label'],
                'format' => $output['format'] ?? 'currency',
                'sort_order' => $output['sort_order'] ?? $index,
            ]);
        }
    }

    /**
     * Jalankan kalkulasi secara deterministic (§113), simpan session, kembalikan outputs.
     *
     * @param  array<string, mixed>  $inputs
     * @return array{session_id: string, outputs: array<string, float>}
     */
    public function run(Calculator $calculator, array $inputs, ?string $productId = null, ?string $leadId = null): array
    {
        $calculator->loadMissing(['inputs', 'rules', 'outputs']);

        $variables = $this->validateAndCoerceInputs($calculator, $inputs);

        try {
            $evaluated = $this->evaluateRules($calculator, $variables);

            $outputs = $this->mapOutputs($calculator, $evaluated);
        } catch (Throwable $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'formula' => ['Evaluasi formula gagal: '.$e->getMessage()],
            ]);
        }

        $session = DB::transaction(function () use ($calculator, $inputs, $outputs, $leadId) {
            return CalculatorSession::create([
                'tenant_id' => $calculator->tenant_id,
                'calculator_id' => $calculator->id,
                'customer_id' => null,
                'lead_id' => $leadId,
                'input_data' => $inputs,
                'output_data' => $outputs,
            ]);
        });

        return [
            'session_id' => $session->id,
            'outputs' => $outputs,
        ];
    }

    /**
     * Validasi input terhadap definisi calculator_inputs dan ubah ke tipe skalar.
     *
     * @param  array<string, mixed>  $inputs
     * @return array<string, float|bool>
     */
    private function validateAndCoerceInputs(Calculator $calculator, array $inputs): array
    {
        $variables = [];

        foreach ($calculator->inputs->sortBy('sort_order') as $definition) {
            $key = $definition->key;
            $present = array_key_exists($key, $inputs);
            $value = $inputs[$key] ?? null;

            if ($definition->is_required && ! $present) {
                throw ValidationException::withMessages([
                    'inputs.'.$key => ['Field '.$key.' wajib diisi.'],
                ]);
            }

            if (! $present || $value === null || $value === '') {
                $variables[$key] = 0;

                continue;
            }

            $variables[$key] = match ($definition->data_type) {
                'number' => $this->validateNumber($definition, $key, $value),
                'boolean' => $this->validateBoolean($key, $value),
                'select' => $this->validateSelect($definition, $key, $value),
                default => (float) $value,
            };
        }

        return $variables;
    }

    private function validateNumber($definition, string $key, mixed $value): float
    {
        if (! is_numeric($value)) {
            throw ValidationException::withMessages([
                'inputs.'.$key => ['Field '.$key.' harus berupa angka.'],
            ]);
        }

        $number = (float) $value;

        if ($definition->min_value !== null && $number < (float) $definition->min_value) {
            throw ValidationException::withMessages([
                'inputs.'.$key => ['Field '.$key.' minimal '.$definition->min_value.'.'],
            ]);
        }

        if ($definition->max_value !== null && $number > (float) $definition->max_value) {
            throw ValidationException::withMessages([
                'inputs.'.$key => ['Field '.$key.' maksimal '.$definition->max_value.'.'],
            ]);
        }

        return $number;
    }

    private function validateBoolean(string $key, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (in_array($value, [0, 1, '0', '1', 'true', 'false'], true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        throw ValidationException::withMessages([
            'inputs.'.$key => ['Field '.$key.' harus boolean.'],
        ]);
    }

    private function validateSelect($definition, string $key, mixed $value): float
    {
        $options = collect($definition->options ?? [])->map(fn ($option) => (string) ($option['value'] ?? $option));

        if (! $options->contains((string) $value)) {
            throw ValidationException::withMessages([
                'inputs.'.$key => ['Field '.$key.' harus salah satu opsi yang tersedia.'],
            ]);
        }

        return (float) $value;
    }

    /**
     * Evaluasi rule berurutan; rule ke-n boleh memakai hasil rule sebelumnya via `R1`, `R2`, dst.
     *
     * @param  array<string, float|bool>  $variables
     * @return array<int, float>
     */
    private function evaluateRules(Calculator $calculator, array $variables): array
    {
        $results = [];

        foreach ($calculator->rules->sortBy('sort_order')->values() as $index => $rule) {
            $ruleVariables = [...$variables, ...$results];
            $value = (new FormulaEvaluator($ruleVariables))->evaluate($rule->formula);

            $results['R'.($index + 1)] = match ($rule->rounding_policy) {
                'floor' => floor($value),
                'ceil' => ceil($value),
                default => round($value),
            };
        }

        return $results;
    }

    /**
     * Map hasil rule (R1, R2, ...) ke key output sesuai sort_order.
     *
     * @param  array<string, float>  $evaluated
     * @return array<string, float>
     */
    private function mapOutputs(Calculator $calculator, array $evaluated): array
    {
        $outputs = [];

        foreach ($calculator->outputs->sortBy('sort_order')->values() as $index => $output) {
            $outputs[$output->key] = $evaluated['R'.($index + 1)] ?? 0.0;
        }

        return $outputs;
    }
}
