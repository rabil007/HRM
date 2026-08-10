<?php

namespace App\Http\Requests\Settings\MasterData;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVesselRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:vessels,name'],
            'vessel_type_id' => ['required', 'integer', Rule::exists('vessel_types', 'id')],
            'grt' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
            'bhp' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
            'official_no' => ['nullable', 'string', 'max:100'],
            'call_sign' => ['nullable', 'string', 'max:100'],
            'imo_no' => ['nullable', 'string', 'max:100'],
            'certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
