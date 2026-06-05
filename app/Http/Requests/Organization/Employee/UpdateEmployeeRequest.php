<?php

namespace App\Http\Requests\Organization\Employee;

use App\Http\Requests\Organization\Employee\Concerns\ValidatesEmployeeNumber;
use App\Models\Employee;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeRequest extends FormRequest
{
    use ValidatesEmployeeNumber;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $companyId = (int) $this->attributes->get('current_company_id');
        $employeeId = (int) $this->route('employee')?->id;

        $rules = [
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
            'manager_id' => [
                'nullable',
                'integer',
                Rule::exists('employees', 'id')
                    ->where(fn ($q) => $q->where('company_id', $companyId))
                    ->where(fn ($q) => $q->where('id', '!=', $employeeId)),
            ],
            'employee_no' => $this->employeeNumberRules($companyId, $employeeId),
            'name' => ['required', 'string', 'max:200'],
            'image' => ['nullable', 'image', 'max:4096'],
            'remove_image' => ['sometimes', 'boolean'],
            'date_of_birth' => ['nullable', 'date'],
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
            'emirates_id' => ['nullable', 'string', 'max:30'],
            'passport_number' => ['nullable', 'string', 'max:50'],
            'labor_card_number' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive,on_leave,terminated'],
            'termination_date' => ['nullable', 'date'],
            'termination_reason' => ['nullable', 'string'],
        ];

        $employee = $this->route('employee');

        if ($employee instanceof Employee) {
            $rules = EmployeeProfileTemplateRequestRules::applyToRules($employee, 'employees', $rules);
        }

        return $this->onlyValidatePresentFields($rules);
    }

    /**
     * Profile saves and photo uploads only send fields edited on the form.
     * Template-required keys omitted from partial profile saves must not fail
     * validation when they are not present in the request.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function onlyValidatePresentFields(array $rules): array
    {
        foreach (array_keys($rules) as $attribute) {
            if (! $this->has($attribute) && ! $this->hasFile($attribute)) {
                unset($rules[$attribute]);
            }
        }

        return $rules;
    }
}
