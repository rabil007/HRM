<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewTimelineWarningCode;
use App\Enums\CrewTimesheetPreparationStatus;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewTimesheet;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use App\Models\User;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use App\Support\Payroll\Actions\GenerateCrewPayroll;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use App\Support\Payroll\CrewTimeline\Actions\ApplyCrewTimesheetPreparation;
use App\Support\Payroll\CrewTimeline\Actions\ApproveCrewTimesheetPreparation;
use App\Support\Payroll\CrewTimeline\Actions\SubmitCrewTimesheetPreparation;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

function overlapWarningExists(int $preparationId): bool
{
    return CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparationId)
        ->where('warning_code', CrewTimelineWarningCode::OverlappingPhases->value)
        ->exists();
}

/**
 * @return Collection<int, CrewTimesheetPreparationLine>
 */
function payableLinesCovering(int $preparationId, string $date)
{
    return CrewTimesheetPreparationLine::query()
        ->where('crew_timesheet_preparation_id', $preparationId)
        ->where('days', '>', 0)
        ->whereDate('from_date', '<=', $date)
        ->whereDate('to_date', '>=', $date)
        ->get();
}

function makeDailyCrewTimelineFixtures(): array
{
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $contract = EmployeeContract::factory()->create([
        'employee_id' => $employee->id,
        'company_id' => $company->id,
        'payroll_category' => PayrollCategory::Crew,
        'salary_structure' => ContractSalaryStructure::Daily,
        'status' => 'active',
        'start_date' => '2026-01-01',
        'end_date' => null,
        'basic_salary' => 100,
        'site_allowance' => 30,
        'supplementary_allowance' => 20,
    ]);

    (new SyncContractSalaryComponentsFromContract)->handle($contract);
    app(ApplyContractSalaryRevision::class)->handle($contract->fresh(), [
        'basic_salary' => 100,
        'site_allowance' => 30,
        'supplementary_allowance' => 20,
    ], '2026-01-01', 'Timeline fixture rates');
    $employee->refresh();

    $period = PayrollPeriod::factory()->for($company)->crewOperations()->create([
        'status' => PayrollPeriodStatus::Draft,
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'payment_date' => '2026-07-31',
    ]);

    $vessel = makeCrewMovementVessel('Timeline Vessel', $company);

    $assignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-TL-'.fake()->unique()->numerify('######'),
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Active,
        'source' => 'manual',
    ]);

    return compact('user', 'company', 'employee', 'rank', 'period', 'assignment', 'vessel');
}

function addTimelinePhase(
    CrewAssignment $assignment,
    CrewPhaseCode $code,
    int $sequence,
    string $start,
    ?string $end,
    CrewPhaseStatus $status = CrewPhaseStatus::Completed,
): CrewAssignmentPhase {
    return CrewAssignmentPhase::query()->create([
        'company_id' => $assignment->company_id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => $code,
        'sequence' => $sequence,
        'status' => $status,
        'actual_start_at' => CarbonImmutable::parse($start, 'Asia/Dubai'),
        'actual_end_at' => $end !== null ? CarbonImmutable::parse($end, 'Asia/Dubai') : null,
    ]);
}

function grantApplyPermissions(User $user, Company $company, array $extra = []): void
{
    grantCompanyPermissions($user, $company, array_values(array_unique(array_merge([
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.prepare',
        'payroll.crew_timesheets.submit',
        'payroll.crew_timesheets.approve',
        'payroll.crew_timesheets.return',
        'payroll.crew_timesheets.apply_approved',
        'payroll.crew_timesheets.create',
        'payroll.crew_timesheets.update',
    ], $extra))));
}

/**
 * @return array{preparation: CrewTimesheetPreparation, approver: User}
 */
/**
 * @param  array<string, mixed>  $financial
 * @return array{preparation: CrewTimesheetPreparation, timesheet: CrewTimesheet, record: PayrollRecord|null}
 */
function runDailyCrewPayrollPipeline(
    array $fixtures,
    ?User $approver = null,
    array $financial = [],
): array {
    grantApplyPermissions($fixtures['user'], $fixtures['company']);
    $approver ??= $fixtures['user'];

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    app(SubmitCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation,
        $fixtures['user'],
        (int) $fixtures['company']->id,
    );
    app(ApproveCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation->fresh(),
        $approver,
        (int) $fixtures['company']->id,
    );
    app(ApplyCrewTimesheetPreparation::class)->handle(
        $fixtures['period'],
        $preparation->fresh(),
        $approver,
        (int) $fixtures['company']->id,
    );

    $timesheet = CrewTimesheet::query()
        ->where('period_id', $fixtures['period']->id)
        ->where('employee_id', $fixtures['employee']->id)
        ->firstOrFail();

    if ($financial !== []) {
        $timesheet->update($financial);
    }

    app(GenerateCrewPayroll::class)->handle($fixtures['period']->fresh());

    $record = PayrollRecord::query()
        ->where('period_id', $fixtures['period']->id)
        ->where('employee_id', $fixtures['employee']->id)
        ->first();

    return [
        'preparation' => $preparation->fresh(),
        'timesheet' => $timesheet->fresh(['segments']),
        'record' => $record,
    ];
}

function makeSeptemberDailyCrewPeriod(array $fixtures): PayrollPeriod
{
    $fixtures['period']->update([
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'payment_date' => '2026-09-30',
    ]);

    return $fixtures['period']->fresh();
}

function prepareApprovedTimeline(array $fixtures, ?User $approver = null): array
{
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-03 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::OnVessel, 2, '2026-07-04 08:00:00', '2026-07-15 18:00:00');
    addTimelinePhase($fixtures['assignment'], CrewPhaseCode::DemobStandby, 3, '2026-07-16 08:00:00', '2026-07-18 18:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $fixtures['period'],
        (int) $fixtures['company']->id,
        (int) $fixtures['user']->id,
    );

    $approver ??= User::factory()->create();
    grantApplyPermissions($approver, $fixtures['company']);

    $preparation->update([
        'status' => CrewTimesheetPreparationStatus::Approved,
        'submitted_by' => $fixtures['user']->id,
        'submitted_at' => now(),
        'approved_by' => $approver->id,
        'approved_at' => now(),
    ]);

    return compact('preparation', 'approver');
}
