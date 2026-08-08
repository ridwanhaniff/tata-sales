<?php

namespace App\Http\Requests\Lead;

use Illuminate\Foundation\Http\FormRequest;

class StoreLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer.name' => ['required', 'string', 'max:255'],
            'customer.phone' => ['required', 'string', 'max:30'],
            'customer.email' => ['nullable', 'email', 'max:255'],
            'product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'variant_id' => ['nullable', 'uuid', 'exists:product_variants,id'],
            'calculator_session_id' => ['nullable', 'uuid', 'exists:calculator_sessions,id'],
            'campaign_id' => ['nullable', 'uuid', 'exists:campaigns,id'],
            'source' => ['sometimes', 'string', 'in:form,whatsapp,chat,manual,api'],
            'consent_marketing' => ['required', 'boolean'],
            'provider_event_id' => ['nullable', 'string', 'max:255'],
            'utm' => ['sometimes', 'array'],
            'utm.utm_source' => ['nullable', 'string', 'max:100'],
            'utm.utm_medium' => ['nullable', 'string', 'max:100'],
            'utm.utm_campaign' => ['nullable', 'string', 'max:100'],
            'utm.utm_content' => ['nullable', 'string', 'max:100'],
            'utm.utm_term' => ['nullable', 'string', 'max:100'],
        ];
    }
}
