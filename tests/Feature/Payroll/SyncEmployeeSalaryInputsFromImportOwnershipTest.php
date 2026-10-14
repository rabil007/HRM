<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\PayrollCategory;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Models\SalaryInput;
use App\Support\CrewOperations\Reset\CrewOperationsResetSalaryInputOwnership;
use App\Support\CrewOperations\Reset\CrewOperationsResetScope;
use App\Support\Payroll\Actions\SyncEmployeeSalaryInputsFromImport;
use App\Support\Payroll\ProvisionDefaultSalaryInputTypes;

test('crew timesheet import sync preserves manual salary input notes and ownership when updating', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-02-01',
        'end_date' => '2026-02-28',
    ]);

    $employee = Employee::factory()->forCompany($company)->create();
    $typeId = salaryInputTypeId($company, 'bonus');

    $manualNotes = 'Client-approved discretionary bonus';

    $manualInput = SalaryInput::factory()->for($company)->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'salary_input_type_id' => $typeId,
        'amount' => 100,
        'notes' => $manualNotes,
        CrewOperationsResetSalaryInputOwnership::COLUMN => false,
    ]);

    app(SyncEmployeeSalaryInputsFromImport::class)->handle(
        $period,
        $employee,
        [$typeId => 250.0],
        [$typeId],
    );

    $manualInput->refresh();

    expect($manualInput->amount)->toBe('250.00')
        ->and($manualInput->notes)->toBe($manualNotes)
        ->and(CrewOperationsResetSalaryInputOwnership::isOwned($manualInput))->toBeFalse();
});

test('crew timesheet import sync marks newly created salary inputs as crew-operations-owned', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-31',
    ]);

    $employee = Employee::factory()->forCompany($company)->create();
    $typeId = salaryInputTypeId($company, 'bonus');

    app(SyncEmployeeSalaryInputsFromImport::class)->handle(
        $period,
        $employee,
        [$typeId => 180.0],
        [$typeId],
    );

    $imported = SalaryInput::query()
        ->where('period_id', $period->id)
        ->where('employee_id', $employee->id)
        ->where('salary_input_type_id', $typeId)
        ->firstOrFail();

    expect($imported->amount)->toBe('180.00')
        ->and(CrewOperationsResetSalaryInputOwnership::isOwned($imported))->toBeTrue();
});

test('crew reset preserves manual salary inputs updated by crew timesheet import sync', function () {
    $fixtures = makeDailyCrewTimelineFixtures();
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 1, '2026-07-01 08:00:00', '2026-07-15 18:00:00', CrewPhaseStatus::Completed);
    runDailyCrewPayrollPipeline($fixtures);

    app(ProvisionDefaultSalaryInputTypes::class)->handle((int) $fixtures['company']->id);
    $typeId = salaryInputTypeId($fixtures['company'], 'bonus');

    $manualInput = SalaryInput::factory()->for($fixtures['company'])->create([
        'employee_id' => $fixtures['employee']->id,
        'period_id' => $fixtures['period']->id,
        'salary_input_type_id' => $typeId,
        'amount' => 90,
        'notes' => 'Manual adjustment before import',
        CrewOperationsResetSalaryInputOwnership::COLUMN => false,
    ]);

    app(SyncEmployeeSalaryInputsFromImport::class)->handle(
        $fixtures['period'],
        $fixtures['employee'],
        [$typeId => 120.0],
        [$typeId],
    );

    $manualInput->refresh();

    expect($manualInput->amount)->toBe('120.00')
        ->and(CrewOperationsResetSalaryInputOwnership::isOwned($manualInput))->toBeFalse();

    $scope = CrewOperationsResetScope::resolve($fixtures['company']->id);

    expect($scope->deletableSalaryInputIds)->not->toContain($manualInput->id)
        ->and($scope->preservedAmbiguousSalaryInputIds)->toContain($manualInput->id);

    runCrewResetWithConfirmation($this, $fixtures['company']->id)->assertSuccessful();

    expect(SalaryInput::query()->whereKey($manualInput->id)->exists())->toBeTrue()
        ->and(SalaryInput::query()->whereKey($manualInput->id)->value('notes'))->toBe('Manual adjustment before import');
});
