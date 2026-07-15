<?php

use App\Enums\PayrollCategory;
use App\Models\Employee;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use App\Support\Contracts\Actions\UpsertEmployeeContract;
use Carbon\Carbon;

test('mirror effective salary revisions command applies revisions that became effective this month', function () {
    Carbon::setTestNow('2026-02-10');

    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $contract = app(UpsertEmployeeContract::class)->handle(
        $company->id,
        $employee,
        [
            'start_date' => '2026-01-01',
            'status' => 'active',
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'daily',
            'basic_salary' => 1000,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
        ],
    );

    app(ApplyContractSalaryRevision::class)->handle(
        $contract->fresh(),
        [
            'basic_salary' => 2000,
            'site_allowance' => 80,
            'supplementary_allowance' => 40,
        ],
        '2026-03-01',
        'Scheduled increase',
    );

    expect((float) $contract->fresh()->basic_salary)->toBe(1000.0);

    Carbon::setTestNow('2026-03-01');

    $this->artisan('contracts:mirror-effective-salary-revisions')
        ->assertSuccessful()
        ->expectsOutputToContain('Mirrored 1 contract(s).');

    expect((float) $contract->fresh()->basic_salary)->toBe(2000.0)
        ->and((float) $contract->fresh()->site_allowance)->toBe(80.0);
});

test('mirror effective salary revisions command ignores still-future revisions', function () {
    Carbon::setTestNow('2026-02-10');

    ['company' => $company] = makePayrollFixtures();
    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $contract = app(UpsertEmployeeContract::class)->handle(
        $company->id,
        $employee,
        [
            'start_date' => '2026-01-01',
            'status' => 'active',
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'daily',
            'basic_salary' => 1000,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
        ],
    );

    app(ApplyContractSalaryRevision::class)->handle(
        $contract->fresh(),
        [
            'basic_salary' => 2000,
            'site_allowance' => 80,
            'supplementary_allowance' => 40,
        ],
        '2026-04-01',
        'Later increase',
    );

    $this->artisan('contracts:mirror-effective-salary-revisions')
        ->assertSuccessful()
        ->expectsOutputToContain('Mirrored 0 contract(s).');

    expect((float) $contract->fresh()->basic_salary)->toBe(1000.0);
});

test('mirror effective salary revisions command can be limited to a single company', function () {
    Carbon::setTestNow('2026-02-10');

    ['company' => $companyA] = makePayrollFixtures();
    ['company' => $companyB] = makePayrollFixtures();

    $employeeA = Employee::factory()->forCompany($companyA)->create(['status' => 'active']);
    $employeeB = Employee::factory()->forCompany($companyB)->create(['status' => 'active']);

    $contractA = app(UpsertEmployeeContract::class)->handle(
        $companyA->id,
        $employeeA,
        [
            'start_date' => '2026-01-01',
            'status' => 'active',
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'daily',
            'basic_salary' => 1000,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
        ],
    );

    $contractB = app(UpsertEmployeeContract::class)->handle(
        $companyB->id,
        $employeeB,
        [
            'start_date' => '2026-01-01',
            'status' => 'active',
            'payroll_category' => PayrollCategory::Crew->value,
            'salary_structure' => 'daily',
            'basic_salary' => 1000,
            'site_allowance' => 50,
            'supplementary_allowance' => 25,
        ],
    );

    foreach ([$contractA, $contractB] as $contract) {
        app(ApplyContractSalaryRevision::class)->handle(
            $contract->fresh(),
            [
                'basic_salary' => 2000,
                'site_allowance' => 80,
                'supplementary_allowance' => 40,
            ],
            '2026-03-01',
            'Scheduled increase',
        );
    }

    Carbon::setTestNow('2026-03-05');

    $this->artisan('contracts:mirror-effective-salary-revisions', [
        '--company' => $companyA->id,
    ])->assertSuccessful();

    expect((float) $contractA->fresh()->basic_salary)->toBe(2000.0)
        ->and((float) $contractB->fresh()->basic_salary)->toBe(1000.0);
});
