<?php

use App\Enums\CrewTimesheetApprovalStatus;
use App\Enums\CrewTimesheetSource;
use App\Enums\PayrollCategory;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparation;
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

test('crew payroll board row without timesheet exposes not entered status and source', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);
    $employee = createCrewEmployeeWithContract($company, 'NO-TS-1', 50, 50, 50);

    $row = crewPayrollBoardRow($employee, null, $period);

    expect($row['approval_status'])->toBe('not_entered')
        ->and($row['approval_status_label'])->toBe('Not Entered')
        ->and($row['operational_source'])->toBe('not_entered')
        ->and($row['operational_source_label'])->toBe('Not Entered')
        ->and($row['is_filled'])->toBeFalse();
});

test('crew payroll board row for applied crew operations timesheet exposes applied status and source', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);
    $employee = createCrewEmployeeWithContract($company, 'CO-APP-1', 50, 50, 50);

    $preparation = CrewTimesheetPreparation::factory()
        ->forPeriod($period)
        ->applied()
        ->create();

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::CrewOperations,
        'approval_status' => CrewTimesheetApprovalStatus::Approved,
        'crew_timesheet_preparation_id' => $preparation->id,
    ]);

    $row = crewPayrollBoardRow($employee, $timesheet->fresh(), $period);

    expect($row['approval_status'])->toBe('applied')
        ->and($row['approval_status_label'])->toBe('Applied/Approved')
        ->and($row['operational_source'])->toBe('crew_operations')
        ->and($row['operational_source_label'])->toBe('Crew Operations');
});

test('crew payroll board row for approved import timesheet exposes approved status and excel import source', function () {
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

    expect($row['approval_status'])->toBe('approved')
        ->and($row['approval_status_label'])->toBe('Approved')
        ->and($row['operational_source'])->toBe('import')
        ->and($row['operational_source_label'])->toBe('Excel Import');
});

test('crew payroll board row for manual draft timesheet exposes draft status and manual source', function () {
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

    expect($row['approval_status'])->toBe('draft')
        ->and($row['approval_status_label'])->toBe('Draft')
        ->and($row['operational_source'])->toBe('manual')
        ->and($row['operational_source_label'])->toBe('Manual');
});

test('crew payroll board row for submitted manual timesheet exposes submitted status', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);
    $employee = createCrewEmployeeWithContract($company, 'MAN-SUB-1', 50, 50, 50);

    $timesheet = CrewTimesheet::factory()->submitted()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Manual,
    ]);

    $row = crewPayrollBoardRow($employee, $timesheet, $period);

    expect($row['approval_status'])->toBe('submitted')
        ->and($row['approval_status_label'])->toBe('Submitted')
        ->and($row['operational_source'])->toBe('manual');
});

test('crew payroll board row for returned import timesheet exposes returned status', function () {
    ['company' => $company] = makePayrollFixtures();
    $period = PayrollPeriod::factory()->for($company)->create([
        'payroll_category' => PayrollCategory::Crew,
    ]);
    $employee = createCrewEmployeeWithContract($company, 'IMP-RET-1', 50, 50, 50);

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::Import,
        'approval_status' => CrewTimesheetApprovalStatus::Returned,
    ]);

    $row = crewPayrollBoardRow($employee, $timesheet, $period);

    expect($row['approval_status'])->toBe('returned')
        ->and($row['approval_status_label'])->toBe('Returned')
        ->and($row['operational_source'])->toBe('import');
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
        ->and($row['approval_status'])->toBe('not_applicable')
        ->and($row['approval_status_label'])->toBe('Not applicable')
        ->and($row['operational_source'])->toBe('monthly_crew')
        ->and($row['operational_source_label'])->toBe('Monthly Crew');
});
