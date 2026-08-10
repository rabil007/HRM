<?php

use App\Enums\ContractSalaryStructure;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use App\Support\Payroll\ResolveCrewContractForWorkDate;

test('soft-deleted salary revision history blocks baseline fallback', function () {
    ['company' => $company] = makePayrollFixtures();

    $employee = createCrewEmployeeWithContract($company, 'ARREARS-SOFT-REV-BLOCK', 220, 0, 0);
    $contract = $employee->fresh()->currentContract;

    expect($contract)->not->toBeNull();

    $contract->update([
        'start_date' => '2026-01-01',
        'end_date' => null,
        'salary_structure' => ContractSalaryStructure::Daily,
    ]);

    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 180,
        'site_allowance' => 0,
        'supplementary_allowance' => 0,
    ], '2026-06-01', 'June rates');

    $revisions = $contract->fresh()->salaryRevisions()->get();

    expect($revisions)->not->toBeEmpty();

    $revisions->each->delete();

    $resolved = app(ResolveCrewContractForWorkDate::class)
        ->resolveSalaryRevision($contract->fresh(['salaryRevisions']), '2026-06-25');

    expect($resolved['revision'])->toBeNull()
        ->and($resolved['issue'])->not->toBeNull()
        ->and($resolved['issue']['code'])->toBe('missing_historical_salary_revision');
});

test('contract that never had salary revisions may use baseline components', function () {
    ['company' => $company] = makePayrollFixtures();

    $employee = createCrewEmployeeWithContract($company, 'ARREARS-BASELINE-OK', 220, 0, 0);
    $contract = $employee->fresh()->currentContract;

    expect($contract)->not->toBeNull();

    $contract->update([
        'start_date' => '2026-01-01',
        'end_date' => null,
        'salary_structure' => ContractSalaryStructure::Daily,
    ]);

    expect($contract->salaryRevisions()->withTrashed()->exists())->toBeFalse();

    $resolved = app(ResolveCrewContractForWorkDate::class)
        ->resolveSalaryRevision($contract->fresh(['salaryRevisions']), '2026-06-25');

    expect($resolved['revision'])->toBeNull()
        ->and($resolved['issue'])->toBeNull();
});
