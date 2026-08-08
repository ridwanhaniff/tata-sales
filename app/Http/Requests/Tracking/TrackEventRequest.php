<?php

namespace App\Http\Requests\Tracking;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TrackEventRequest extends FormRequest
{
    public const EVENT_TYPES = [
        'page_view', 'product_view', 'promo_view', 'calculator_start',
        'calculator_complete', 'form_start', 'form_complete', 'cta_click',
        'whatsapp_click', 'chat_start',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_type' => ['required', Rule::in(self::EVENT_TYPES)],
            'visitor_id' => ['nullable', 'string', 'max:100'],
            'campaign_id' => ['nullable', 'uuid'],
            'event_data' => ['nullable', 'array'],
        ];
    }
}
