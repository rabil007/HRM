<?php

namespace App\Support\Employees\Resources;

use App\Models\Employee;
use App\Support\Departments\ResolveDepartmentEffectiveManager;
use App\Support\Users\UserAvatar;

final class EmployeeDetailResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'user' => $employee->user_id ? [
                'id' => $employee->user_id,
                'name' => $employee->user?->name,
                'email' => $employee->user?->email,
                'avatar' => UserAvatar::url($employee->user?->avatar),
            ] : null,
            'branch' => $employee->branch_id ? [
                'id' => $employee->branch_id,
                'name' => $employee->branch?->name,
            ] : null,
            'department' => $employee->department_id ? [
                'id' => $employee->department_id,
                'name' => $employee->department?->name,
            ] : null,
            'position' => $employee->position_id ? [
                'id' => $employee->position_id,
                'title' => $employee->position?->title,
            ] : null,
            'rank_id' => $employee->rank_id,
            'rank' => $employee->rank_id ? [
                'id' => $employee->rank_id,
                'name' => $employee->rank?->name,
            ] : null,
            'project_id' => $employee->project_id,
            'project' => $employee->project_id ? [
                'id' => $employee->project_id,
                'title' => $employee->project?->title,
            ] : null,
            'manager' => ResolveDepartmentEffectiveManager::managerPayloadForEmployee($employee),
            'employee_no' => $employee->employee_no,
            'name' => $employee->name,
            'image' => $employee->image,
            'date_of_birth' => $employee->date_of_birth,
            'hire_date' => $employee->hire_date?->toDateString(),
            'place_of_birth' => $employee->place_of_birth,
            'gender' => $employee->gender,
            'gender_id' => $employee->gender_id,
            'gender_ref' => $employee->gender_id ? [
                'id' => $employee->gender_id,
                'name' => $employee->genderRef?->name,
            ] : null,
            'religion' => $employee->religion,
            'religion_id' => $employee->religion_id,
            'religion_ref' => $employee->religion_id ? [
                'id' => $employee->religion_id,
                'name' => $employee->religionRef?->name,
            ] : null,
            'visa_type_id' => $employee->visa_type_id,
            'visa_type_ref' => $employee->visa_type_id ? [
                'id' => $employee->visa_type_id,
                'name' => $employee->visaTypeRef?->name,
            ] : null,
            'company_visa_type_id' => $employee->company_visa_type_id,
            'company_visa_type_ref' => $employee->company_visa_type_id ? [
                'id' => $employee->company_visa_type_id,
                'name' => $employee->companyVisaTypeRef?->name,
            ] : null,
            'approval_location_ids' => $employee->relationLoaded('approvalLocations')
                ? $employee->approvalLocations->pluck('id')->values()->all()
                : [],
            'approval_locations' => $employee->relationLoaded('approvalLocations')
                ? $employee->approvalLocations->map(fn ($location) => [
                    'id' => $location->id,
                    'name' => $location->name,
                ])->values()->all()
                : [],
            'sssa_option_ids' => $employee->relationLoaded('sssaOptions')
                ? $employee->sssaOptions->pluck('id')->values()->all()
                : [],
            'sssa_options' => $employee->relationLoaded('sssaOptions')
                ? $employee->sssaOptions->map(fn ($option) => [
                    'id' => $option->id,
                    'name' => $option->name,
                ])->values()->all()
                : [],
            'nationality_id' => $employee->nationality_id,
            'nationality_ref' => $employee->nationality_id ? [
                'id' => $employee->nationality_id,
                'name' => $employee->nationalityRef?->name,
                'code' => $employee->nationalityRef?->code,
            ] : null,
            'marital_status' => $employee->marital_status,
            'spouse_name' => $employee->spouse_name,
            'personal_email' => $employee->personal_email,
            'work_email' => $employee->work_email,
            'phone' => $employee->phone,
            'nearest_airport' => $employee->nearest_airport,
            'phone_home_country' => $employee->phone_home_country,
            'emergency_contact' => $employee->emergency_contact,
            'emergency_phone' => $employee->emergency_phone,
            'address' => $employee->address,
            'start_date' => $employee->currentContract?->start_date,
            'end_date' => $employee->currentContract?->end_date,
            'labor_contract_id' => $employee->currentContract?->labor_contract_id,
            'basic_salary' => $employee->currentContract?->basic_salary,
            'housing_allowance' => $employee->currentContract?->housing_allowance,
            'transport_allowance' => $employee->currentContract?->transport_allowance,
            'other_allowances' => $employee->currentContract?->other_allowances,
            'supplementary_allowance' => $employee->currentContract?->supplementary_allowance,
            'site_allowance' => $employee->currentContract?->site_allowance,
            'bank_id' => $employee->primaryBankAccount?->bank_id,
            'bank' => $employee->primaryBankAccount?->bank_id ? [
                'id' => $employee->primaryBankAccount->bank_id,
                'name' => $employee->primaryBankAccount->bank?->name,
            ] : null,
            'iban' => $employee->primaryBankAccount?->iban,
            'account_name' => $employee->primaryBankAccount?->account_name,
            'salary_payment_method' => $employee->salary_payment_method?->value ?? 'bank_transfer',
            'salary_payment_method_label' => $employee->salary_payment_method?->label() ?? 'Bank transfer',
            'emirates_id' => $employee->emirates_id,
            'passport_number' => $employee->passport_number,
            'status' => $employee->status,
            'termination_date' => $employee->termination_date,
            'termination_reason' => $employee->termination_reason,
            'employee_profile_template' => $employee->employee_profile_template_id ? [
                'id' => $employee->employee_profile_template_id,
                'name' => $employee->employeeProfileTemplate?->name,
            ] : null,
            'employee_profile_template_id' => $employee->employee_profile_template_id,
            'created_at' => $employee->created_at,
            'updated_at' => $employee->updated_at,
        ];
    }
}
