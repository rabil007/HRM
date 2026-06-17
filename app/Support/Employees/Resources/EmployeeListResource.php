<?php

namespace App\Support\Employees\Resources;

use App\Models\Employee;
use App\Models\EmployeeDeployment;
use App\Support\CrewDeployments\EmployeeCrewStatusPresenter;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateResolver;

final class EmployeeListResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Employee $employee, ?EmployeeDeployment $latestDeployment = null): array
    {
        $payload = [
            'id' => $employee->id,
            'user_id' => $employee->user_id,
            'branch_id' => $employee->branch_id,
            'department_id' => $employee->department_id,
            'position_id' => $employee->position_id,
            'manager_id' => $employee->manager_id,
            'employee_no' => $employee->employee_no,
            'image' => $employee->image,
            'name' => $employee->name,
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
            'work_email' => $employee->work_email,
            'personal_email' => $employee->personal_email,
            'phone' => $employee->phone,
            'phone_home_country' => $employee->phone_home_country,
            'nearest_airport' => $employee->nearest_airport,
            'emergency_contact' => $employee->emergency_contact,
            'emergency_phone' => $employee->emergency_phone,
            'date_of_birth' => $employee->date_of_birth,
            'hire_date' => $employee->hire_date?->toDateString(),
            'place_of_birth' => $employee->place_of_birth,
            'gender_id' => $employee->gender_id,
            'gender_ref' => $employee->gender_id ? [
                'id' => $employee->gender_id,
                'name' => $employee->genderRef?->name,
            ] : null,
            'religion_id' => $employee->religion_id,
            'religion_ref' => $employee->religion_id ? [
                'id' => $employee->religion_id,
                'name' => $employee->religionRef?->name,
            ] : null,
            'nationality_id' => $employee->nationality_id,
            'nationality_ref' => $employee->nationality_id ? [
                'id' => $employee->nationality_id,
                'name' => $employee->nationalityRef?->name,
                'code' => $employee->nationalityRef?->code,
            ] : null,
            'marital_status' => $employee->marital_status,
            'spouse_name' => $employee->spouse_name,
            'passport_number' => $employee->passport_number,
            'emirates_id' => $employee->emirates_id,
            'bank_id' => $employee->primaryBankAccount?->bank_id,
            'bank' => $employee->primaryBankAccount?->bank_id ? [
                'id' => $employee->primaryBankAccount->bank_id,
                'name' => $employee->primaryBankAccount->bank?->name,
            ] : null,
            'status' => $employee->status,
            'iban' => $employee->primaryBankAccount?->iban,
            'start_date' => $employee->currentContract?->start_date,
            'contract_type' => $employee->currentContract?->contract_type,
            'end_date' => $employee->currentContract?->end_date,
            'labor_contract_id' => $employee->currentContract?->labor_contract_id,
            'created_at' => $employee->created_at,
        ];

        if (EmployeeProfileTemplateResolver::employeeFieldVisible($employee->employeeProfileTemplate, 'crew_status')) {
            $payload['crew_status'] = EmployeeCrewStatusPresenter::fromDeployment($latestDeployment);
        }

        return $payload;
    }
}
