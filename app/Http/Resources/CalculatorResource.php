<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CalculatorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'status' => $this->status,
            'inputs' => $this->whenLoaded('inputs', fn () => $this->inputs->sortBy('sort_order')->values()->map(fn ($input) => [
                'id' => $input->id,
                'key' => $input->key,
                'label' => $input->label,
                'data_type' => $input->data_type,
                'min_value' => $input->min_value !== null ? (float) $input->min_value : null,
                'max_value' => $input->max_value !== null ? (float) $input->max_value : null,
                'options' => $input->options,
                'is_required' => (bool) $input->is_required,
                'sort_order' => $input->sort_order,
            ])),
            'rules' => $this->whenLoaded('rules', fn () => $this->rules->sortBy('sort_order')->values()->map(fn ($rule) => [
                'id' => $rule->id,
                'formula' => $rule->formula,
                'rounding_policy' => $rule->rounding_policy,
                'sort_order' => $rule->sort_order,
            ])),
            'outputs' => $this->whenLoaded('outputs', fn () => $this->outputs->sortBy('sort_order')->values()->map(fn ($output) => [
                'id' => $output->id,
                'key' => $output->key,
                'label' => $output->label,
                'format' => $output->format,
                'sort_order' => $output->sort_order,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
