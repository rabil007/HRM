<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\PayrollCategory;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Support\Payroll\ResolveCrewContractForPayrollPeriod;

function hardeningContract(Employee $employee, array $overrides): EmployeeContract
{
    return EmployeeContract::factory()->create(array_merge([
        'employee_id' => $employee->id,
        'company_id' => $employee->company_id,
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => ContractSalaryStructure::Daily,
        'status' => 'active',
        'basic_salary' => 100,
    ], $overrides));
}

function hardeningPeriod($company, string $start, string $end): PayrollPeriod
{
    return PayrollPeriod::factory()->for($company)->crewOperations()->create([
        'start_date' => $start,
        'end_date' => $end,
        'payment_date' => $end,
    ]);
}

test('resolver returns the daily contract for a historical period and the monthly contract for a current period', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    hardeningContract($employee, [
        'salary_structure' => ContractSalaryStructure::Daily,
        'start_date' => '2025-01-01',
        'end_date' => '2025-12-31',
    ]);
    hardeningContract($employee, [
        'salary_structure' => ContractSalaryStructure::Monthly,
        'start_date' => '2026-01-01',
        'end_date' => null,
    ]);

    $resolver = app(ResolveCrewContractForPayrollPeriod::class);

    $historical = $resolver->resolve($employee, hardeningPeriod($company, '2025-06-01', '2025-06-30'));
    $current = $resolver->resolve($employee, hardeningPeriod($company, '2026-06-01', '2026-06-30'));

    expect($historical?->resolvedSalaryStructure())->toBe(ContractSalaryStructure::Daily)
        ->and($current?->resolvedSalaryStructure())->toBe(ContractSalaryStructure::Monthly);
});

test('resolver returns the crew contract applicable to the period even when a newer office contract exists', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $crew = hardeningContract($employee, [
        'start_date' => '2025-01-01',
        'end_date' => '2025-12-31',
    ]);
    hardeningContract($employee, [
        'payroll_category' => PayrollCategory::Office,
        'salary_structure' => ContractSalaryStructure::Monthly,
        'start_date' => '2026-01-01',
        'end_date' => null,
    ]);

    $resolved = app(ResolveCrewContractForPayrollPeriod::class)
        ->resolve($employee, hardeningPeriod($company, '2025-06-01', '2025-06-30'));

    expect($resolved?->id)->toBe($crew->id)
        ->and($resolved?->payroll_category)->toBe(PayrollCategory::Crew);
});

test('resolver picks the expired contract for its period and the new contract for a later period', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $old = hardeningContract($employee, [
        'start_date' => '2024-01-01',
        'end_date' => '2024-12-31',
    ]);
    $new = hardeningContract($employee, [
        'start_date' => '2026-01-01',
        'end_date' => null,
    ]);

    $resolver = app(ResolveCrewContractForPayrollPeriod::class);

    expect($resolver->resolve($employee, hardeningPeriod($company, '2024-06-01', '2024-06-30'))?->id)->toBe($old->id)
        ->and($resolver->resolve($employee, hardeningPeriod($company, '2026-06-01', '2026-06-30'))?->id)->toBe($new->id);
});

test('resolver reports ambiguous overlapping crew contracts', function () {
    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    hardeningContract($employee, ['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    hardeningContract($employee, ['start_date' => '2026-03-01', 'end_date' => null]);

    $period = hardeningPeriod($company, '2026-06-01', '2026-06-30');

    expect(app(ResolveCrewContractForPayrollPeriod::class)->hasAmbiguousOverlap($employee, $period))->toBeTrue();
});
