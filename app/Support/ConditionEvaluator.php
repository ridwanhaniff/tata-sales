<?php

namespace App\Support;

use Illuminate\Support\Arr;

/**
 * Evaluator kondision JSON sederhana yang dipakai workflow & followup rules.
 * Config: {field, operator, value} — field diambil dari contexts via dot-notation.
 * Operator: ==, !=, >, >=, <, <=, in, not_in, contains.
 */
class ConditionEvaluator
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $condition
     */
    public static function passes(array $context, ?array $condition): bool
    {
        if (! $condition || ! isset($condition['field'])) {
            return true;
        }

        $actual = Arr::get($context, $condition['field']);
        $operator = (string) ($condition['operator'] ?? '==');
        $value = $condition['value'] ?? null;

        return match ($operator) {
            '==' => $actual == $value,
            '!=' => $actual != $value,
            '>' => $actual > $value,
            '>=' => $actual >= $value,
            '<' => $actual < $value,
            '<=' => $actual <= $value,
            'in' => is_array($value) && in_array($actual, $value, true),
            'not_in' => is_array($value) && ! in_array($actual, $value, true),
            'contains' => is_string($actual) && is_string($value) && str_contains($actual, $value),
            default => true,
        };
    }
}
