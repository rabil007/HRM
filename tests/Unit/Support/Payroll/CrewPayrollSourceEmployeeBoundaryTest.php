<?php

use App\Models\CrewAssignment;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Support\Payroll\CrewTimeline\CrewPayrollSourceEmployeeBoundary;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;

test('stable apply boundary falls back to all active employees when no crew source exists yet', function () {
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
