<?php

use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\Employee;
use App\Models\PayrollPeriod;
use App\Support\Payroll\CrewTimesheetResource;
use Illuminate\Support\Carbon;

/**
 * @return array<string, mixed>
 */
function crewPayrollBoardRow(
    Employee $employee,
    ?CrewTimesheet $timesheet,
    PayrollPeriod $period,
): array {
    $employee->loadMissing('currentContract');

    return CrewTimesheetResource::toBoardRow(
        $employee,
        $timesheet,
        $period->id,
        Carbon::parse($period->end_date),
    );
}

test('crew payroll board row without timesheet exposes not entered readiness and source', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);
    $employee = createCrewEmployeeWithContract($company, 'NO-TS-1', 50, 50, 50);

    $row = crewPayrollBoardRow($employee, null, $period);

    expect($row['readiness_status'])->toBe('not_entered')
        ->and($row['readiness_status_label'])->toBe('Not Entered')
        ->and($row['approval_status'])->toBe('not_entered')
        ->and($row['approval_status_label'])->toBe('Not Entered')
        ->and($row['operational_source'])->toBe('not_entered')
        ->and($row['operational_source_label'])->toBe('Not Entered')
        ->and($row['is_filled'])->toBeFalse();
});

test('crew payroll board row for crew operations timesheet exposes ready status and assignments source', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);
    $employee = createCrewEmployeeWithContract($company, 'CO-APP-1', 50, 50, 50);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::CrewOperations,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
    ]);

    $row = crewPayrollBoardRow($employee, $timesheet->fresh(), $period);

    expect($row['readiness_status'])->toBe('ready')
        ->and($row['readiness_status_label'])->toBe('Ready')
        ->and($row['approval_status'])->toBe('ready')
        ->and($row['operational_source'])->toBe('crew_operations')
        ->and($row['operational_source_label'])->toBe('Crew Assignments');
});

test('crew payroll board row for import timesheet exposes ready status and excel import source', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);
    $employee = createCrewEmployeeWithContract($company, 'IMP-APP-1', 50, 50, 50);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Import,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
    ]);

    $row = crewPayrollBoardRow($employee, $timesheet, $period);

    expect($row['readiness_status'])->toBe('ready')
        ->and($row['approval_status'])->toBe('ready')
        ->and($row['operational_source'])->toBe('import')
        ->and($row['operational_source_label'])->toBe('Excel Import');
});

test('crew payroll board row for manual timesheet exposes ready status and manual source', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);
    $employee = createCrewEmployeeWithContract($company, 'MAN-DRF-1', 50, 50, 50);

    $timesheet = CrewTimesheet::factory()->draft()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
    ]);

    $row = crewPayrollBoardRow($employee, $timesheet, $period);

    expect($row['readiness_status'])->toBe('ready')
        ->and($row['approval_status'])->toBe('ready')
        ->and($row['operational_source'])->toBe('manual')
        ->and($row['operational_source_label'])->toBe('Manual');
});

test('crew payroll board row for monthly crew employee without timesheet exposes monthly source', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);
    $employee = createCrewMonthlyEmployeeWithContract(
        $company,
        'MTH-CREW-1',
        5000,
        1000,
        500,
        250,
    );

    $row = crewPayrollBoardRow($employee, null, $period);

    expect($row['salary_structure'])->toBe('monthly')
        ->and($row['readiness_status'])->toBe('not_applicable')
        ->and($row['readiness_status_label'])->toBe('Not applicable')
        ->and($row['approval_status'])->toBe('not_applicable')
        ->and($row['operational_source'])->toBe('monthly_crew')
        ->and($row['operational_source_label'])->toBe('Monthly Crew');
});
