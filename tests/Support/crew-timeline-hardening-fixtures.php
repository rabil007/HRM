<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewTimesheetPreparation;
use App\Models\CrewTimesheetPreparationLine;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\Rank;
use App\Models\User;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     marineDept: Department,
 *     officeDept: Department,
 *     marine: Employee,
 *     office: Employee,
 *     period: PayrollPeriod,
 *     preparation: CrewTimesheetPreparation
 * }
 */
function setupCrewTimelineHardeningFixtures(): array
{
    ['user' => $user, 'company' => $company, 'marineDept' => $marineDept, 'officeDept' => $officeDept, 'marineEmployee' => $marine, 'officeEmployee' => $office] = makeEmployeeVisibilityFixtures();

    $rank = Rank::query()->create([
        'name' => 'Seaman',
        'is_active' => true,
    ]);

    $marineRank = Position::query()->create([
        'company_id' => $company->id,
        'department_id' => $marineDept->id,
        'title' => 'Able Seaman',
    ]);
    $officeRank = Position::query()->create([
        'company_id' => $company->id,
        'department_id' => $officeDept->id,
        'title' => 'Office Crew',
    ]);

    $marine->update(['position_id' => $marineRank->id, 'rank_id' => $rank->id]);
    $office->update(['position_id' => $officeRank->id, 'rank_id' => $rank->id]);

    foreach ([$marine, $office] as $emp) {
        $contract = EmployeeContract::factory()->create([
            'employee_id' => $emp->id,
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
        $emp->refresh();
    }

    $period = PayrollPeriod::factory()->for($company)->crewOperations()->create([
        'status' => PayrollPeriodStatus::Draft,
        'payroll_category' => PayrollCategory::Crew,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'payment_date' => '2026-07-31',
    ]);

    $vessel = makeCrewMovementVessel('Hardening Vessel', $company);

    $marineAssignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-TL-M'.fake()->unique()->numerify('#####'),
        'employee_id' => $marine->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Active,
        'source' => 'manual',
    ]);

    $officeAssignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-TL-O'.fake()->unique()->numerify('#####'),
        'employee_id' => $office->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Active,
        'source' => 'manual',
    ]);

    addTimelinePhase($marineAssignment, CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-03 18:00:00');
    addTimelinePhase($marineAssignment, CrewPhaseCode::OnVessel, 2, '2026-07-04 08:00:00', '2026-07-20 18:00:00');

    addTimelinePhase($officeAssignment, CrewPhaseCode::JoinStandby, 1, '2026-07-01 08:00:00', '2026-07-03 18:00:00');
    addTimelinePhase($officeAssignment, CrewPhaseCode::OnVessel, 2, '2026-07-04 08:00:00', '2026-07-20 18:00:00');

    $preparation = app(PrepareCrewTimesheetTimeline::class)->handle(
        $period,
        (int) $company->id,
        (int) $user->id,
    );

    grantCompanyPermissions($user, $company, [
        'payroll.crew_timesheets.view',
        'payroll.crew_timesheets.prepare',
        'payroll.crew_timesheets.submit',
        'payroll.crew_timesheets.approve',
        'payroll.crew_timesheets.return',
        'payroll.crew_timesheets.skip_timeline',
    ]);
    restrictUserToDepartments($user, $company, [$marineDept->id]);

    return compact('user', 'company', 'marineDept', 'officeDept', 'marine', 'office', 'period', 'preparation');
}

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     marineDept: Department,
 *     officeDept: Department,
 *     marine: Employee,
 *     office: Employee,
 *     period: PayrollPeriod,
 *     preparation: CrewTimesheetPreparation
 * }
 */
function setupMarineOnlyCrewTimelineFixtures(): array
{
    $f = setupCrewTimelineHardeningFixtures();

    CrewTimesheetPreparationLine::query()
        ->where('company_id', $f['company']->id)
        ->where('crew_timesheet_preparation_id', $f['preparation']->id)
        ->where('employee_id', $f['office']->id)
        ->delete();

    return $f;
}
