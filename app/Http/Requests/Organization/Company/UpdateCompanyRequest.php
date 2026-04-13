<?php

namespace App\Http\Requests\Organization\Company;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $companyId = $this->route('company')?->id;

        return [
            'logo' => ['nullable', 'image', 'max:2048'],
            'name' => ['required', 'string', 'max:200'],
            'slug' => [
                'nullable',
                'string',
                'max:200',
                Rule::unique('companies', 'slug')->ignore($companyId),
            ],
            'industry' => ['nullable', 'string', 'max:100'],
            'company_size' => ['nullable', 'string', 'max:50'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'tax_id' => ['nullable', 'string', 'max:100'],
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:200'],
            'website' => ['nullable', 'string', 'max:300'],
            'currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'timezone' => ['nullable', 'string', 'max:100'],
            'payroll_cycle' => ['nullable', 'in:monthly,biweekly,weekly'],
            'working_days' => ['nullable', 'array'],
            'working_days.*' => ['integer'],
            'wps_agent_code' => ['nullable', 'string', 'max:100'],
            'wps_mol_uid' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,suspended,inactive'],
        ];
    }
}
