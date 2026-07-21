<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewTimesheetPayCategory;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\CrewTimesheetSource;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\PayrollPeriod;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

test('crew timeline preparation schema includes new tables and crew timesheet columns', function () {
    expect(Schema::hasTable('crew_timesheet_preparations'))->toBeTrue()
        ->and(Schema::hasTable('crew_timesheet_preparation_lines'))->toBeTrue()
        ->and(Schema::hasColumn('crew_timesheets', 'sign_on_standby_from'))->toBeTrue()
        ->and(Schema::hasColumn('crew_timesheets', 'sign_on_standby_to'))->toBeTrue()
        ->and(Schema::hasColumn('crew_timesheets', 'sign_on_standby_days'))->toBeTrue()
        ->and(Schema::hasColumn('crew_timesheets', 'sign_off_standby_from'))->toBeTrue()
        ->and(Schema::hasColumn('crew_timesheets', 'sign_off_standby_to'))->toBeTrue()
        ->and(Schema::hasColumn('crew_timesheets', 'sign_off_standby_days'))->toBeTrue()
        ->and(Schema::hasColumn('crew_timesheets', 'source'))->toBeTrue()
        ->and(Schema::hasColumn('crew_timesheets', 'crew_timesheet_preparation_id'))->toBeTrue()
        ->and(Schema::hasColumn('crew_timesheets', 'operational_approved_by'))->toBeTrue()
        ->and(Schema::hasColumn('crew_timesheets', 'operational_approved_at'))->toBeTrue()
        ->and(Schema::hasColumn('crew_timesheets', 'movement_source_hash'))->toBeTrue();
});

test('preparation version is unique within a company and payroll period', function () {
    ['company' => $company] = makePayrollFixtures();

    $period = PayrollPeriod::factory()->for($company)->create();

    CrewTimesheetPreparation::factory()->forPeriod($period)->version(1)->create();

    expect(fn () => CrewTimesheetPreparation::factory()->forPeriod($period)->version(1)->create())
        ->toThrow(QueryException::class);
});

test('preparation version can repeat for another company on the same payroll period version number', function () {
    ['company' => $companyA] = makePayrollFixtures();
    ['company' => $companyB] = makePayrollFixtures();

    $periodA = PayrollPeriod::factory()->for($companyA)->create(['name' => 'April 2026 A']);
    $periodB = PayrollPeriod::factory()->for($companyB)->create(['name' => 'April 2026 B']);

    $preparationA = CrewTimesheetPreparation::factory()->forPeriod($periodA)->version(1)->create();
    $preparationB = CrewTimesheetPreparation::factory()->forPeriod($periodB)->version(1)->create();

    expect($preparationA->version)->toBe(1)
        ->and($preparationB->version)->toBe(1)
        ->and($preparationA->company_id)->not->toBe($preparationB->company_id);
});

test('preparation and line relationships preserve tenant ownership fields', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();
    $period = PayrollPeriod::factory()->for($company)->create();

    $assignment = CrewAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
    ]);

    $preparation = CrewTimesheetPreparation::factory()
        ->forPeriod($period)
        ->create([
            'prepared_by' => $user->id,
        ]);

    $line = CrewTimesheetPreparationLine::factory()
        ->forPreparation($preparation)
        ->forAssignment($assignment)
        ->create([
            'pay_category' => CrewTimesheetPayCategory::SignOnStandby,
            'phase_code' => CrewPhaseCode::JoinStandby,
            'from_date' => '2026-04-01',
            'to_date' => '2026-04-03',
            'days' => 3,
        ]);

    expect($preparation->company_id)->toBe($company->id)
        ->and($preparation->payrollPeriod->is($period))->toBeTrue()
        ->and($preparation->lines)->toHaveCount(1)
        ->and($line->company_id)->toBe($company->id)
        ->and($line->preparation->is($preparation))->toBeTrue()
        ->and($line->assignment->is($assignment))->toBeTrue()
        ->and($line->employee_id)->toBe($assignment->employee_id);
});

test('preparation and line models cast enum fields', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();
    $period = PayrollPeriod::factory()->for($company)->create();
    $assignment = CrewAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
    ]);

    $preparation = CrewTimesheetPreparation::factory()
        ->forPeriod($period)
        ->create(['status' => CrewTimesheetPreparationStatus::Submitted]);

    $line = CrewTimesheetPreparationLine::factory()
        ->forPreparation($preparation)
        ->forAssignment($assignment)
        ->create([
            'phase_code' => CrewPhaseCode::OnVessel,
            'pay_category' => CrewTimesheetPayCategory::Onsite,
        ]);

    expect($preparation->status)->toBe(CrewTimesheetPreparationStatus::Submitted)
        ->and($line->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($line->pay_category)->toBe(CrewTimesheetPayCategory::Onsite);
});

test('repeated phases can be stored as separate preparation lines', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();
    $period = PayrollPeriod::factory()->for($company)->create();
    $preparation = CrewTimesheetPreparation::factory()->forPeriod($period)->create();

    $assignment = CrewAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
    ]);

    $phaseOne = CrewAssignmentPhase::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
    ]);

    $phaseTwo = CrewAssignmentPhase::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 2,
    ]);

    CrewTimesheetPreparationLine::factory()->forPreparation($preparation)->create([
        'employee_id' => $employee->id,
        'crew_assignment_id' => $assignment->id,
        'crew_assignment_phase_id' => $phaseOne->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-04-01',
        'to_date' => '2026-04-10',
        'days' => 10,
    ]);

    CrewTimesheetPreparationLine::factory()->forPreparation($preparation)->create([
        'employee_id' => $employee->id,
        'crew_assignment_id' => $assignment->id,
        'crew_assignment_phase_id' => $phaseTwo->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'pay_category' => CrewTimesheetPayCategory::Onsite,
        'from_date' => '2026-04-11',
        'to_date' => '2026-04-20',
        'days' => 10,
    ]);

    expect(CrewTimesheetPreparationLine::query()->where('crew_timesheet_preparation_id', $preparation->id)->count())->toBe(2);
});

test('legacy generic standby columns are removed from the crew timesheets table', function () {
    expect(Schema::hasColumn('crew_timesheets', 'standby_from'))->toBeFalse()
        ->and(Schema::hasColumn('crew_timesheets', 'standby_to'))->toBeFalse()
        ->and(Schema::hasColumn('crew_timesheets', 'standby_days'))->toBeFalse()
        ->and(Schema::hasColumn('crew_timesheets', 'unpaid_leave_days'))->toBeTrue();
});

test('crew timesheet casts and preparation relationships for operational metadata', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();
    $period = PayrollPeriod::factory()->for($company)->create();
    $preparation = CrewTimesheetPreparation::factory()->forPeriod($period)->create();

    $timesheet = CrewTimesheet::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'source' => CrewTimesheetSource::CrewOperations,
        'crew_timesheet_preparation_id' => $preparation->id,
        'operational_approved_by' => $user->id,
        'operational_approved_at' => now(),
        'movement_source_hash' => 'abc123',
        'sign_off_standby_days' => 2,
    ]);

    expect($timesheet->source)->toBe(CrewTimesheetSource::CrewOperations)
        ->and($timesheet->preparation->is($preparation))->toBeTrue()
        ->and($timesheet->operationalApprovedBy->is($user))->toBeTrue()
        ->and($timesheet->operational_approved_at)->not->toBeNull()
        ->and((string) $timesheet->sign_off_standby_days)->toBe('2.00')
        ->and($period->crewTimesheetPreparations)->toHaveCount(1)
        ->and($preparation->crewTimesheets)->toHaveCount(1);
});

test('timeline preparation migration rollback removes crew timesheet extension columns', function () {
    expect(Schema::hasColumn('crew_timesheets', 'movement_source_hash'))->toBeTrue();

    Artisan::call('migrate:rollback', [
        '--path' => 'database/migrations/2026_07_20_120002_add_timeline_preparation_fields_to_crew_timesheets_table.php',
        '--force' => true,
    ]);

    expect(Schema::hasColumn('crew_timesheets', 'movement_source_hash'))->toBeFalse()
        ->and(Schema::hasColumn('crew_timesheets', 'sign_on_standby_from'))->toBeFalse();

    Artisan::call('migrate', [
        '--path' => 'database/migrations/2026_07_20_120002_add_timeline_preparation_fields_to_crew_timesheets_table.php',
        '--force' => true,
    ]);
});

test('payroll period and assignment relationships expose preparation lines', function () {
    ['company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();
    $period = PayrollPeriod::factory()->for($company)->create();
    $preparation = CrewTimesheetPreparation::factory()->forPeriod($period)->create();

    $assignment = CrewAssignment::factory()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
    ]);

    $phase = CrewAssignmentPhase::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::DemobStandby,
    ]);

    $line = CrewTimesheetPreparationLine::factory()
        ->forPreparation($preparation)
        ->create([
            'employee_id' => $employee->id,
            'crew_assignment_id' => $assignment->id,
            'crew_assignment_phase_id' => $phase->id,
            'pay_category' => CrewTimesheetPayCategory::SignOffStandby,
        ]);

    expect($assignment->timesheetPreparationLines)->toHaveCount(1)
        ->and($phase->timesheetPreparationLines->first()->is($line))->toBeTrue()
        ->and($employee->crewTimesheetPreparationLines->first()->is($line))->toBeTrue();
});
