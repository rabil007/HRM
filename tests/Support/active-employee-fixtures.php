<?php

use App\Models\AttendanceRecord;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\Employee;

/**
 * @return array{
 *     company: Company,
 *     branch: Branch,
 *     officeDepartment: Department,
 *     activeEmployee: Employee,
 *     inactiveEmployee: Employee,
 *     terminatedEmployee: Employee
 * }
 */
function makeActiveOnlyScopeFixtures(): array
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'AO1'],
        ['name' => 'Active Only Land', 'dial_code' => '+903', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'AO1'],
        ['name' => 'Active Only Currency', 'symbol' => 'A$', 'is_active' => true],
    );

    $company = Company::query()->create([
        'name' => 'ActiveOnlyCo',
        'slug' => 'activeonlyco-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $branch = Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'HQ',
        'code' => 'HQ',
        'status' => 'active',
        'is_headquarters' => true,
    ]);

    $officeDepartment = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Office',
        'code' => 'OFF',
        'status' => 'active',
    ]);

    $activeEmployee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'department_id' => $officeDepartment->id,
        'employee_no' => 'ACT001',
        'name' => 'Active Employee',
        'status' => 'active',
    ]);

    $inactiveEmployee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'department_id' => $officeDepartment->id,
        'employee_no' => 'INA001',
        'name' => 'Inactive Employee',
        'status' => 'inactive',
    ]);

    $terminatedEmployee = Employee::query()->create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'department_id' => $officeDepartment->id,
        'employee_no' => 'TRM001',
        'name' => 'Terminated Employee',
        'status' => 'terminated',
    ]);

    return compact('company', 'branch', 'officeDepartment', 'activeEmployee', 'inactiveEmployee', 'terminatedEmployee');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function activeEmployeeAttendancePayload(Employee $employee, array $overrides = []): array
{
    return array_merge([
        'employee_id' => $employee->id,
        'date' => '2026-06-10',
        'clock_in' => '2026-06-10 08:00:00',
        'clock_out' => '2026-06-10 17:00:00',
        'status' => AttendanceRecord::STATUS_PRESENT,
        'late_minutes' => 0,
        'notes' => 'Manual entry',
    ], $overrides);
}
