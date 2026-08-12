<?php

namespace App\Http\Requests\Crm;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validasi pengaturan webhook CRM keluar/masuk per tenant (§77-78).
 * secret/inbound_secret opsional — kosongkan untuk reset ulang saat
 * kirim test (regenerate manual oleh pemakai).
 */
class UpdateCrmWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => ['nullable', 'url', 'max:500'],
            'secret' => ['nullable', 'string', 'max:255'],
            'inbound_secret' => ['nullable', 'string', 'max:255'],
        ];
    }
}
