<?php

namespace App\Http\Requests\Organization\Employee;

use App\Enums\SalaryPaymentMethod;
use App\Http\Requests\Organization\Employee\Concerns\ValidatesEmployeeNumber;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmployeeRequest extends FormRequest
{
    use ValidatesEmployeeNumber;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');

        return [
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists('departments', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'position_id' => [
                'nullable',
                'integer',
                Rule::exists('positions', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'rank_id' => ['nullable', 'integer', Rule::exists('ranks', 'id')],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
            'employee_no' => $this->employeeNumberRules($companyId),
            'name' => ['required', 'string', 'max:200'],
            'image' => ['nullable', 'image', 'max:4096'],
            'date_of_birth' => ['nullable', 'date'],
            'hire_date' => ['nullable', 'date'],
            'place_of_birth' => ['nullable', 'string', 'max:150'],
            'gender_id' => ['nullable', 'integer', Rule::exists('genders', 'id')],
            'religion_id' => ['nullable', 'integer', Rule::exists('religions', 'id')],
            'visa_type_id' => ['nullable', 'integer', Rule::exists('visa_types', 'id')->where('is_active', true)],
            'company_visa_type_id' => ['nullable', 'integer', Rule::exists('company_visa_types', 'id')->where('is_active', true)],
            'approval_location_ids' => ['nullable', 'array'],
            'approval_location_ids.*' => ['integer', Rule::exists('approval_locations', 'id')->where('is_active', true)],
            'sssa_option_ids' => ['nullable', 'array'],
            'sssa_option_ids.*' => ['integer', Rule::exists('sssa_options', 'id')->where('is_active', true)],
            'nationality_id' => ['nullable', 'integer', Rule::exists('countries', 'id')],
            'marital_status' => ['nullable', 'in:single,married,divorced,widowed'],
            'spouse_name' => ['nullable', 'string', 'max:200'],
            'personal_email' => ['nullable', 'string', 'email', 'max:200'],
            'work_email' => ['nullable', 'string', 'email', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30'],
            'nearest_airport' => ['nullable', 'string', 'max:150'],
            'phone_home_country' => ['nullable', 'string', 'max:30'],
            'emergency_contact' => ['nullable', 'string', 'max:200'],
            'emergency_phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'start_date' => ['required', 'date'],
            'contract_type' => ['required', 'in:limited,unlimited,part_time,contract'],
            'end_date' => ['nullable', 'date'],
            'labor_contract_id' => ['nullable', 'string', 'max:100'],
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'housing_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'other_allowances' => ['nullable', 'numeric', 'min:0'],
            'supplementary_allowance' => ['nullable', 'numeric', 'min:0'],
            'site_allowance' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
            'iban' => ['nullable', 'string', 'max:50'],
            'bank_id' => ['nullable', 'integer', Rule::exists('banks', 'id')],
            'account_name' => ['nullable', 'string', 'max:200'],
            'emirates_id' => ['nullable', 'string', 'max:30'],
            'passport_number' => ['nullable', 'string', 'max:50'],
            'labor_card_number' => ['nullable', 'string', 'max:100'],
            'salary_payment_method' => ['nullable', Rule::enum(SalaryPaymentMethod::class)],
            'employee_profile_template_id' => [
                'nullable',
                'integer',
                Rule::exists('employee_profile_templates', 'id')->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'status' => ['nullable', 'in:active,inactive,on_leave,terminated'],
            'termination_date' => ['nullable', 'date'],
            'termination_reason' => ['nullable', 'string'],

            'documents' => ['nullable', 'array'],
            'documents.*.type' => ['required_with:documents', 'string', 'max:200'],
            'documents.*.files' => ['required_with:documents.*.type', 'array', 'min:1'],
            'documents.*.files.*' => ['file'],
            'documents.*.issue_date' => ['nullable', 'date'],
            'documents.*.expiry_date' => ['nullable', 'date'],
            'documents.*.document_number' => ['nullable', 'string', 'max:120'],
        ];
    }
}
