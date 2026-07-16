<?php

namespace App\Http\Requests\Organization;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrewAssignmentRequest extends FormRequest
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
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('company_id', $companyId)],
            'rank_id' => ['nullable', 'integer', Rule::exists('ranks', 'id')],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->where('company_id', $companyId)],
            'vessel_id' => ['nullable', 'integer', Rule::exists('vessels', 'id')],
            'company_visa_type_id' => ['nullable', 'integer', Rule::exists('company_visa_types', 'id')->where('company_id', $companyId)],
            'planned_join_at' => ['nullable', 'date'],
            'planned_signoff_at' => ['nullable', 'date'],
            'planned_travel_at' => ['nullable', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
