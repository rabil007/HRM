<?php

use App\Enums\PayrollCategory;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\Payroll\PayrollOverviewSummary;

test('payroll department employee counts exclude soft-deleted employees contracts and other companies', function () {
    ['company' => $company] = makePayrollFixtures();
    $other = makePayrollFixtures();

    $department = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations',
        'status' => 'active',
    ]);

    $counted = Employee::factory()->forCompany($company)->create([
        'department_id' => $department->id,
        'status' => 'active',
    ]);

    $softDeletedEmployee = Employee::factory()->forCompany($company)->create([
        'department_id' => $department->id,
        'status' => 'active',
    ]);
    $softDeletedEmployee->delete();

    $withDeletedContracts = Employee::factory()->forCompany($company)->create([
        'department_id' => $department->id,
        'status' => 'active',
    ]);
    EmployeeContract::query()
        ->where('employee_id', $withDeletedContracts->id)
        ->get()
        ->each(fn (EmployeeContract $contract) => $contract->delete());

    $foreign = Employee::factory()->forCompany($other['company'])->create([
        'status' => 'active',
    ]);
    expect($foreign->contracts()->where('status', 'active')->exists())->toBeTrue()
        ->and(PayrollCategory::Office)->not->toBeNull();

    $method = new ReflectionMethod(PayrollOverviewSummary::class, 'departmentEmployeeCounts');
    $counts = $method->invoke(null, $company->id);
    $operations = collect($counts)->firstWhere('name', 'Operations');

    expect(Employee::query()->where('company_id', $company->id)->where('department_id', $department->id)->count())->toBe(2)
        ->and($operations)->not->toBeNull()
        ->and($operations['count'])->toBe(1)
        ->and($counted->id)->toBeInt();
});
