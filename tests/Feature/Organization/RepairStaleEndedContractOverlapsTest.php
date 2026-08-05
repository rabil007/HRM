<?php

use App\Enums\PayrollCategory;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\Contracts\Actions\RepairStaleEndedContractOverlaps;

test('repair command caps ended contracts that overlap a later contract', function () {
    ['company' => $company] = makeVisaTypeContractFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);

    $staleEnded = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'start_date' => '2024-08-14',
        'end_date' => '2026-08-14',
        'status' => 'ended',
        'basic_salary' => 200,
    ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'start_date' => '2026-02-03',
        'status' => 'active',
        'basic_salary' => 220,
    ]);

    $repairs = app(RepairStaleEndedContractOverlaps::class)->handle((int) $company->id);

    expect($repairs)->toHaveCount(1)
        ->and($repairs[0]['contract_id'])->toBe($staleEnded->id)
        ->and($repairs[0]['new_end_date'])->toBe('2026-02-02')
        ->and($staleEnded->fresh()->end_date?->toDateString())->toBe('2026-02-02');
});

test('repair command dry run does not persist changes', function () {
    ['company' => $company] = makeVisaTypeContractFixtures();
    $employee = Employee::factory()->forCompany($company)->withoutDefaultContract()->create(['status' => 'active']);

    $staleEnded = EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'start_date' => '2024-08-14',
        'end_date' => '2026-08-14',
        'status' => 'ended',
        'basic_salary' => 200,
    ]);

    EmployeeContract::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'payroll_category' => PayrollCategory::Crew->value,
        'salary_structure' => 'daily',
        'start_date' => '2026-02-03',
        'status' => 'active',
        'basic_salary' => 220,
    ]);

    $repairs = app(RepairStaleEndedContractOverlaps::class)->handle((int) $company->id, dryRun: true);

    expect($repairs)->toHaveCount(1)
        ->and($staleEnded->fresh()->end_date?->toDateString())->toBe('2026-08-14');
});
