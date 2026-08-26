<?php

namespace App\Http\Requests\Organization\Employee;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class InterpretEmployeeSmartSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $companyId = $this->attributes->get('current_company_id');

        return $this->user()?->can('employees.view') === true
            && is_numeric($companyId)
            && (int) $companyId > 0;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'prompt' => ['required', 'string', 'min:2', 'max:200'],
            'company_id' => ['prohibited'],
            'search' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'department_id' => ['prohibited'],
            'position_id' => ['prohibited'],
            'status' => ['prohibited'],
            'manager_id' => ['prohibited'],
            'gender_id' => ['prohibited'],
            'nationality_id' => ['prohibited'],
            'visa_type_id' => ['prohibited'],
            'company_visa_type_id' => ['prohibited'],
            'rank_id' => ['prohibited'],
            'approval_location_id' => ['prohibited'],
            'sssa_option_id' => ['prohibited'],
            'crew_status' => ['prohibited'],
            'role_id' => ['prohibited'],
            'emirates_id' => ['prohibited'],
            'emirates_id_presence' => ['prohibited'],
            'missing_fields' => ['prohibited'],
            'present_fields' => ['prohibited'],
            'filters' => ['prohibited'],
            'provider' => ['prohibited'],
            'model' => ['prohibited'],
            'api_key' => ['prohibited'],
            'openai_api_key' => ['prohibited'],
            'openrouter_api_key' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('prompt')) {
            $this->merge([
                'prompt' => trim((string) $this->input('prompt')),
            ]);
        }
    }

    public function prompt(): string
    {
        return (string) $this->validated('prompt');
    }
}
