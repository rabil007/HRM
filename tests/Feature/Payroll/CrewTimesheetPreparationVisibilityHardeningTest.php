<?php

use App\Enums\ContractSalaryStructure;
use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\PayrollCategory;
use App\Enums\PayrollPeriodStatus;
use App\Models\CrewAssignment;
use App\Models\CrewTimesheetPreparationSkip;
use App\Models\EmployeeContract;
use App\Models\PayrollPeriod;
use App\Models\Position;
use App\Models\Rank;
use App\Support\Contracts\Actions\ApplyContractSalaryRevision;
use App\Support\Payroll\Actions\SyncContractSalaryComponentsFromContract;
use App\Support\Payroll\CrewTimeline\PrepareCrewTimesheetTimeline;
use Inertia\Testing\AssertableInertia as Assert;

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

test('restricted user cannot view hidden employees on preparation review page', function () {
    $f = setupCrewTimelineHardeningFixtures();

    $response = $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->get(route('payroll.crew-timeline.show', [$f['period'], $f['preparation']]));

    $response->assertOk();
    $response->assertInertia(function (Assert $page) use ($f) {
        $page->component('payroll/crew-timeline/show');

        $employeeIds = collect($page->toArray()['props']['employees'])->pluck('employee_id')->all();
        expect($employeeIds)->toContain($f['marine']->id)
            ->and($employeeIds)->not->toContain($f['office']->id);

        $deptTree = $page->toArray()['props']['department_tree'];
        $deptIds = collect($deptTree)->pluck('id')->filter()->all();
        expect($deptIds)->toContain($f['marineDept']->id)
            ->and($deptIds)->not->toContain($f['officeDept']->id);
    });
});

test('restricted user cannot skip hidden employee in preparation', function () {
    $f = setupCrewTimelineHardeningFixtures();

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->post(route('payroll.crew-timeline.employee-skip', [$f['period'], $f['preparation'], $f['office']]), [
            'reason' => 'Should be forbidden or not found',
        ])
        ->assertNotFound();
});

test('restricted user cannot restore hidden employee in preparation', function () {
    $f = setupCrewTimelineHardeningFixtures();

    // Create skip for office employee first (as owner/unrestricted)
    CrewTimesheetPreparationSkip::query()->create([
        'company_id' => $f['company']->id,
        'payroll_period_id' => $f['period']->id,
        'crew_timesheet_preparation_id' => $f['preparation']->id,
        'employee_id' => $f['office']->id,
        'skipped_by' => $f['user']->id,
        'skipped_at' => now(),
        'reason' => 'Pre-existing skip',
    ]);

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->delete(route('payroll.crew-timeline.employee-skip.restore', [$f['period'], $f['preparation'], $f['office']]))
        ->assertNotFound();
});
