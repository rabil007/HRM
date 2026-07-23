<?php

use App\Enums\CrewTimesheetApprovalStatus;
use App\Models\CrewTimesheet;
use App\Models\PayrollPeriod;
use App\Support\Payroll\BuildCrewPayrollGenerationPreview;
use Illuminate\Support\Facades\DB;

test('approved timesheets with null source are treated as manual and do not block generation', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->hybridTimesheets()->create([
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
    ]);
    $employee = createCrewEmployeeWithContract($company, 'NULL-SRC-1', 100, 50, 25);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'onsite_days' => 10,
        'onsite_from' => '2026-07-01',
        'onsite_to' => '2026-07-10',
    ]);

    DB::table('crew_timesheets')->where('id', $timesheet->id)->update(['source' => null]);
    $timesheet->refresh();

    expect($timesheet->source)->toBeNull()
        ->and($timesheet->resolvedSource()->value)->toBe('manual');

    $preview = app(BuildCrewPayrollGenerationPreview::class)->handle($period, (int) $company->id);

    expect($preview->ready)->toBeTrue()
        ->and($preview->canGenerate)->toBeTrue()
        ->and($preview->readyCount)->toBe(1)
        ->and($preview->blockingCount)->toBe(0)
        ->and($preview->readyEmployeeIds)->toContain($employee->id);
});
