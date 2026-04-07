<?php

namespace App\Http\Requests\Settings\MasterData;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCountryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'size:3', 'unique:countries,code'],
            'name' => ['required', 'string', 'max:100'],
            'dial_code' => ['nullable', 'string', 'max:10'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
