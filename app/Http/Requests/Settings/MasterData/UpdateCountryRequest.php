<?php

namespace App\Http\Requests\Settings\MasterData;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCountryRequest extends FormRequest
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
            'code' => [
                'required',
                'string',
                'size:3',
                Rule::unique('countries', 'code')->ignore($this->route('country')?->id),
            ],
            'name' => ['required', 'string', 'max:100'],
            'dial_code' => ['nullable', 'string', 'max:10'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
