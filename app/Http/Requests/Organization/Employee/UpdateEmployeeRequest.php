<?php

namespace App\Http\Requests\Organization\Employee;

use App\Enums\SalaryPaymentMethod;
use App\Http\Requests\Organization\Employee\Concerns\ValidatesEmployeeNumber;
use App\Models\Employee;
use App\Support\Attendance\DepartmentAttendanceLeaveGuard;
use App\Support\Attendance\EmployeeHireDateChangeGuard;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use App\Support\Employees\DraftEmployeeNumber;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Employees\ProvisionalEmployeeAccess;
use App\Support\MasterData\ClientAssignmentRules;
use App\Support\Positions\CrewPositionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateEmployeeRequest extends FormRequest
{
    use ValidatesEmployeeNumber;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->prepareEmployeeNumberForValidation();
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
                CrewPositionCatalog::existsCompanyPositionRule($companyId),
            ],

            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')],
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')],
            'employee_no' => $this->employeeNumberRules($companyId, $employeeId),
            'name' => ['required', 'string', 'max:200'],
            'image' => ['nullable', 'image', 'max:4096'],
            'remove_image' => ['sometimes', 'boolean'],
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
            'emirates_id' => ['nullable', 'string', 'max:30'],
            'passport_number' => ['nullable', 'string', 'max:50'],
            'salary_payment_method' => ['nullable', Rule::enum(SalaryPaymentMethod::class)],
            'status' => ['nullable', 'in:active,inactive,on_leave,terminated'],
            'termination_date' => ['nullable', 'date'],
            'termination_reason' => ['nullable', 'string'],
            EmployeeHireDateChangeGuard::ACKNOWLEDGMENT_INPUT => ['sometimes', 'boolean'],
        ];

        $employee = $this->route('employee');

        if ($employee instanceof Employee) {
            $rules = EmployeeProfileTemplateRequestRules::applyToRules($employee, 'employees', $rules);
        }

        return $this->onlyValidatePresentFields($rules);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->has('client_id') || $this->has('project_id')) {
                /** @var Employee|null $employee */
                $employee = $this->route('employee');

                $clientId = $this->has('client_id')
                    ? ($this->input('client_id') !== null && $this->input('client_id') !== ''
                        ? (int) $this->input('client_id')
                        : null)
                    : ($employee?->client_id !== null ? (int) $employee->client_id : null);

                $projectId = $this->has('project_id')
                    ? ($this->input('project_id') !== null && $this->input('project_id') !== ''
                        ? (int) $this->input('project_id')
                        : null)
                    : ($employee?->project_id !== null ? (int) $employee->project_id : null);

                ClientAssignmentRules::projectBelongsToClient($validator, $clientId, $projectId);
            }

            if ($this->has('department_id')) {
                $this->assertDepartmentIsAllowed($validator);
                $this->assertPendingLeaveAllowsDepartmentMove($validator);
            }

            $this->assertDepartmentRequiredForRestrictedProvisionalCompletion($validator);
            $this->assertHireDateChangeAcknowledged($validator);
        });
    }

    private function assertHireDateChangeAcknowledged(Validator $validator): void
    {
        if (! $this->has('hire_date')) {
            return;
        }

        /** @var Employee|null $employee */
        $employee = $this->route('employee');

        if (! $employee instanceof Employee) {
            return;
        }

        $companyId = (int) $this->attributes->get('current_company_id');
        $guard = app(EmployeeHireDateChangeGuard::class);
        $proposedHireDate = $guard->normalizeHireDate($this->input('hire_date'));

        if (! $guard->requiresAcknowledgment($employee, $companyId, $proposedHireDate)) {
            return;
        }

        if (! $this->boolean(EmployeeHireDateChangeGuard::ACKNOWLEDGMENT_INPUT)) {
            $validator->errors()->add(
                EmployeeHireDateChangeGuard::ACKNOWLEDGMENT_INPUT,
                'Please confirm the annual leave allocation warning before saving the hire date change.',
            );
        }
    }

    private function assertPendingLeaveAllowsDepartmentMove(Validator $validator): void
    {
        /** @var Employee|null $employee */
        $employee = $this->route('employee');

        if (! $employee instanceof Employee) {
            return;
        }

        $companyId = (int) $this->attributes->get('current_company_id');
        $message = DepartmentAttendanceLeaveGuard::cannotMoveEmployeeToDepartment(
            $employee,
            $companyId,
            $this->input('department_id'),
        );

        if ($message !== null) {
            $validator->errors()->add('department_id', $message);
        }
    }

    private function assertDepartmentIsAllowed(Validator $validator): void
    {
        $departmentId = $this->input('department_id');

        $user = $this->user();
        if ($user === null) {
            return;
        }

        $companyId = (int) $this->attributes->get('current_company_id');
        $allowedIds = EmployeeVisibilityScope::allowedDepartmentIds($user, $companyId);

        if ($allowedIds === null) {
            return;
        }

        if ($departmentId === null || $departmentId === '') {
            $validator->errors()->add('department_id', 'The selected department is not available.');

            return;
        }

        if ($allowedIds === [] || ! in_array((int) $departmentId, $allowedIds, true)) {
            $validator->errors()->add('department_id', 'The selected department is not available.');
        }
    }

    /**
     * Department-restricted creators must assign an in-scope department when
     * finalizing their owned provisional employee. Unrestricted users keep
     * optional department behavior.
     */
    private function assertDepartmentRequiredForRestrictedProvisionalCompletion(Validator $validator): void
    {
        $user = $this->user();
        /** @var Employee|null $employee */
        $employee = $this->route('employee');

        if ($user === null || ! $employee instanceof Employee) {
            return;
        }

        if ($user->can('employees.update')) {
            return;
        }

        if (! $user->can('employees.create')) {
            return;
        }

        if (! ProvisionalEmployeeAccess::isOwnedBy($user, $employee)) {
            return;
        }

        if (! DraftEmployeeNumber::isDraft($employee->employee_no)) {
            return;
        }

        $companyId = (int) $this->attributes->get('current_company_id');
        $allowedIds = EmployeeVisibilityScope::allowedDepartmentIds($user, $companyId);

        if ($allowedIds === null) {
            return;
        }

        $departmentId = $this->has('department_id')
            ? $this->input('department_id')
            : $employee->department_id;

        if ($departmentId === null || $departmentId === '') {
            $validator->errors()->add(
                'department_id',
                'Select an authorized department before completing this employee.',
            );

            return;
        }

        if ($allowedIds === [] || ! in_array((int) $departmentId, $allowedIds, true)) {
            $validator->errors()->add('department_id', 'The selected department is not available.');
        }
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
