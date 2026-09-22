<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;

/**
 * Ensure the company has a department included in Attendance & Leave.
 */
function ensureIncludedAttendanceLeaveDepartment(Company $company): Department
{
    $existing = Department::query()
        ->where('company_id', $company->id)
        ->where('include_in_attendance_leave', true)
        ->orderBy('id')
        ->first();

    if ($existing !== null) {
        return $existing;
    }

    return Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Attendance & Leave',
        'code' => 'ATL'.fake()->unique()->numerify('##'),
        'status' => 'active',
        'include_in_attendance_leave' => true,
    ]);
}

/**
 * Create an employee eligible for Attendance & Leave (current department included).
 *
 * @param  array<string, mixed>  $attributes
 */
function createAttendanceLeaveEmployee(Company $company, array $attributes = []): Employee
{
    $department = ensureIncludedAttendanceLeaveDepartment($company);

    return Employee::factory()->forCompany($company)->create(array_merge([
        'status' => 'active',
        'department_id' => $department->id,
    ], $attributes));
}
