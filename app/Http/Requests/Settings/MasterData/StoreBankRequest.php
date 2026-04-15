<?php

namespace App\Http\Requests\Settings\MasterData;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBankRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'uae_routing_code_agent_id' => ['nullable', 'string', 'max:50'],
            'country_id' => ['nullable', 'integer', Rule::exists('countries', 'id')],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
