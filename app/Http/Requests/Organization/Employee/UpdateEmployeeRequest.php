<?php

namespace App\Http\Requests\Organization\Employee;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $employeeId = (int) $this->route('employee')?->id;

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
            'manager_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->where(fn ($q) => $q->where('id', '!=', $employeeId)),
            ],
            'employee_no' => [
                'required',
                'string',
                'max:50',
                Rule::unique('employees', 'employee_no')
                    ->ignore($employeeId)
                    ->where(fn ($q) => $q->where('company_id', $companyId)),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date'],
            'place_of_birth' => ['nullable', 'string', 'max:150'],
            'gender_id' => ['nullable', 'integer', Rule::exists('genders', 'id')],
            'religion_id' => ['nullable', 'integer', Rule::exists('religions', 'id')],
            'nationality' => ['nullable', 'string', 'max:100'],
            'marital_status' => ['nullable', 'in:single,married,divorced,widowed'],
            'spouse_name' => ['nullable', 'string', 'max:200'],
            'spouse_birthdate' => ['nullable', 'date'],
            'dependent_children_count' => ['nullable', 'integer', 'min:0', 'max:999'],
            'personal_email' => ['nullable', 'string', 'email', 'max:200'],
            'work_email' => ['nullable', 'string', 'email', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30'],
            'nearest_airport' => ['nullable', 'string', 'max:150'],
            'phone_home_country' => ['nullable', 'string', 'max:30'],
            'cv_source' => ['nullable', 'string', 'max:120'],
            'emergency_contact' => ['nullable', 'string', 'max:200'],
            'emergency_phone' => ['nullable', 'string', 'max:30'],
            'emergency_contact_home_country' => ['nullable', 'string', 'max:200'],
            'emergency_phone_home_country' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'hire_date' => ['required', 'date'],
            'probation_end_date' => ['nullable', 'date'],
            'contract_type' => ['required', 'in:limited,unlimited,part_time,contract'],
            'contract_end_date' => ['nullable', 'date'],
            'labor_contract_id' => ['nullable', 'string', 'max:100'],
            'basic_salary' => ['nullable', 'numeric', 'min:0'],
            'housing_allowance' => ['nullable', 'numeric', 'min:0'],
            'transport_allowance' => ['nullable', 'numeric', 'min:0'],
            'other_allowances' => ['nullable', 'numeric', 'min:0'],
            'bank_name' => ['nullable', 'string', 'max:200'],
            'bank_account_name' => ['nullable', 'string', 'max:200'],
            'iban' => ['nullable', 'string', 'max:50'],
            'bank_id' => ['nullable', 'integer', Rule::exists('banks', 'id')],
            'visa_number' => ['nullable', 'string', 'max:100'],
            'visa_expiry' => ['nullable', 'date'],
            'visa_type_id' => ['nullable', 'integer', Rule::exists('visa_types', 'id')],
            'emirates_id' => ['nullable', 'string', 'max:30'],
            'emirates_id_expiry' => ['nullable', 'date'],
            'passport_number' => ['nullable', 'string', 'max:50'],
            'passport_issued_at' => ['nullable', 'date'],
            'passport_expiry' => ['nullable', 'date'],
            'work_permit_number' => ['nullable', 'string', 'max:100'],
            'work_permit_expiry' => ['nullable', 'date'],
            'labor_card_number' => ['nullable', 'string', 'max:100'],
            'labor_card_expiry' => ['nullable', 'date'],
            'mohre_uid' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,on_leave,terminated'],
            'termination_date' => ['nullable', 'date'],
            'termination_reason' => ['nullable', 'string'],
        ];
    }
}
