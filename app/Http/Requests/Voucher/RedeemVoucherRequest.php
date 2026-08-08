<?php

namespace App\Http\Requests\Voucher;

use Illuminate\Foundation\Http\FormRequest;

class RedeemVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'customer.phone' => ['nullable', 'string', 'max:20'],
            'lead_id' => ['nullable', 'uuid'],
        ];
    }
}
