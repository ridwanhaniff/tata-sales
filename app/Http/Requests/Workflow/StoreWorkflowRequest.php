<?php

namespace App\Http\Requests\Workflow;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'trigger_event' => ['required', 'string', 'max:50'],
            'status' => ['nullable', Rule::in(['active', 'paused', 'draft'])],
            'nodes' => ['required', 'array', 'min:1'],
            'nodes.*.node_type' => ['required', Rule::in(['trigger', 'condition', 'action', 'delay', 'ai', 'human', 'end'])],
            'nodes.*.config' => ['nullable', 'array'],
            'nodes.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'nodes.*.next_node_id' => ['nullable', 'uuid'],
        ];
    }
}
