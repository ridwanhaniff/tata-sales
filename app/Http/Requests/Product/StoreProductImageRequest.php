<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:5'],
            'images.*' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp,gif', 'max:5120'],
            'alt_text' => ['nullable', 'string', 'max:255'],
        ];
    }
}
