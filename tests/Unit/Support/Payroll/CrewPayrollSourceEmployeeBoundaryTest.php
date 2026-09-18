<?php

use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Support\Payroll\CrewTimeline\CrewPayrollSourceEmployeeBoundary;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;

test('stable apply boundary includes all active employees even when crew source already exists', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    $employeeB = Employee::factory()
        ->forCompany($fixtures['company'])
        ->create([
            'rank_id' => $fixtures['rank']->id,
            'status' => 'active',
        ]);

    EmployeeContract::query()->where('employee_id', $employeeB->id)->delete();
    CrewAssignment::query()->where('employee_id', $employeeB->id)->delete();

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $boundary = app(CrewPayrollSourceEmployeeBoundary::class);

    expect($boundary->stableEmployeeIds(
        (int) $fixtures['company']->id,
        $fixtures['period'],
        $preparation,
    ))->toContain((int) $employeeB->id);
});

test('stable apply boundary retains inactive employees already represented in preparation source', function () {
    $fixtures = makeDailyCrewTimelineFixtures();

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $fixtures['employee']->update(['status' => 'inactive']);

    $boundary = app(CrewPayrollSourceEmployeeBoundary::class);

    expect($boundary->existingSourceEmployeeIds(
        (int) $fixtures['company']->id,
        $fixtures['period'],
        $preparation,
    ))->toContain((int) $fixtures['employee']->id)
        ->and($boundary->stableEmployeeIds(
            (int) $fixtures['company']->id,
            $fixtures['period'],
            $preparation,
        ))->toContain((int) $fixtures['employee']->id);
});

test('stable apply boundary includes all active employees when no crew source exists yet', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();

    EmployeeContract::query()->where('employee_id', $employee->id)->delete();
    CrewAssignment::query()->where('employee_id', $employee->id)->delete();

    $period = PayrollPeriod::factory()->for($company)->crewOperations()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'payment_date' => '2026-09-30',
    ]);

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $period,
        (int) $company->id,
        (int) $user->id,
    );

    $boundary = app(CrewPayrollSourceEmployeeBoundary::class);

    expect($boundary->stableEmployeeIds((int) $company->id, $period, $preparation))
        ->toContain((int) $employee->id);
});
