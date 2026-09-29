<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exports\CrewReliefExport;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Vessel;
use App\Support\Reports\CrewRelief\CrewReliefPlanKey;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Maatwebsite\Excel\Facades\Excel;

function authorizeCrewReliefReport(): array
{
    $fixtures = makeCrewAssignmentFixtures();
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'reports.crew_relief.view',
        'reports.crew_relief.export',
        'crew_operations.assignments.view',
    ]);

    $fixtures['vessel'] = makeCrewMovementVessel('Relief Vessel A', $fixtures['company']);

    return $fixtures;
}

test('crew relief report requires authentication and view permission', function () {
    $this->get(route('organization.reports.crew-relief.index'))
        ->assertRedirect(route('login'));

    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $user->update(['current_company_id' => $company->id]);
    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index'))
        ->assertForbidden();
});

test('crew relief report export requires export permission', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $user->update(['current_company_id' => $company->id]);
    grantCompanyPermissions($user, $company, ['reports.crew_relief.view']);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.export'))
        ->assertForbidden();
});

test('report is company scoped and excludes other companies assignments', function () {
    CarbonImmutable::setTestNow('2026-10-01 10:00:00');

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    $myAssignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'assignment_no' => 'CA-MINE-001',
        'planned_signoff_at' => '2026-10-15 00:00:00',
    ]);

    ['company' => $otherCompany, 'employee' => $otherEmployee, 'rank' => $otherRank] = makeCrewAssignmentFixtures();
    $otherVessel = makeCrewMovementVessel('Other Vessel', $otherCompany);
    makeActiveOnVesselAssignment($otherCompany, $otherEmployee, $otherRank, $otherVessel, [
        'assignment_no' => 'CA-OTHER-001',
        'planned_signoff_at' => '2026-10-15 00:00:00',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/reports/crew-relief/index')
            ->has('rows', 1)
            ->where('rows.0.id', $myAssignment->id)
            ->where('rows.0.assignment_no', 'CA-MINE-001')
            ->where('can.export', true)
        );
});

test('report only shows active P4 onboard crew and excludes completed or non-p4 assignments', function () {
    CarbonImmutable::setTestNow('2026-10-01 10:00:00');

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    // P4 onboard assignment
    $onboardAssignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'assignment_no' => 'CA-ONBOARD-001',
        'planned_signoff_at' => '2026-10-20 00:00:00',
    ]);

    // Completed assignment
    $completedEmployee = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    CrewAssignment::factory()->forEmployee($completedEmployee)->completed()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'assignment_no' => 'CA-COMPLETED-001',
        'planned_signoff_at' => '2026-09-20 00:00:00',
    ]);

    // P1 Travel in assignment
    $travelEmployee = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    makeCurrentCrewPhaseAssignment($company, $travelEmployee, $rank, $vessel, CrewPhaseCode::TravelIn, [
        'assignment_no' => 'CA-TRAVEL-001',
        'planned_signoff_at' => '2026-10-25 00:00:00',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.id', $onboardAssignment->id)
            ->where('rows.0.assignment_no', 'CA-ONBOARD-001')
        );
});

test('joined date uses actual P4 start date and days onboard counts correctly', function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    $assignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-JOINED-001',
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Active,
        'planned_join_at' => '2026-09-01 00:00:00', // Should NOT be used as actual joined date
        'planned_signoff_at' => '2026-10-20 00:00:00',
        'source' => 'manual',
    ]);

    $p4Phase = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-09-10 08:00:00', // Actual joined date
    ]);
    $assignment->update(['current_phase_id' => $p4Phase->id]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.joined_date', '2026-09-10')
            ->where('rows.0.days_onboard', 30)
            ->where('rows.0.days_to_signoff', 10)
            ->where('rows.0.days_to_signoff_label', '10 days')
        );
});

test('attention rules: overdue sign-off is flagged as critical', function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'assignment_no' => 'CA-OVERDUE-001',
        'planned_signoff_at' => '2026-10-06 00:00:00', // 4 days ago
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.days_to_signoff', -4)
            ->where('rows.0.days_to_signoff_label', 'Overdue by 4 days')
            ->where('rows.0.attention.level', 'critical')
            ->where('rows.0.attention.label', 'Sign-off overdue by 4 days')
            ->where('summary.overdue_signoffs', 1)
        );
});

test('attention rules: no relief assigned for approaching sign-off is critical', function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'assignment_no' => 'CA-NORELIEF-001',
        'planned_signoff_at' => '2026-10-14 00:00:00', // 4 days away
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.readiness', 'not_assigned')
            ->where('rows.0.attention.level', 'critical')
            ->where('rows.0.attention.label', 'No relief assigned')
            ->where('summary.no_relief_assigned', 1)
            ->where('summary.signing_off_next_7_days', 1)
        );
});

test('attention rules: relief in training (P2B) is flagged as relief not ready warning', function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    $outgoing = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'assignment_no' => 'CA-OUTGOING-001',
        'planned_signoff_at' => '2026-10-18 00:00:00', // 8 days away
    ]);

    $reliefEmployee = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    $incoming = makeCurrentCrewPhaseAssignment($company, $reliefEmployee, $rank, $vessel, CrewPhaseCode::Training, [
        'assignment_no' => 'CA-RELIEF-001',
        'relieves_crew_assignment_id' => $outgoing->id,
        'planned_join_at' => '2026-10-18 00:00:00',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.relief_employee.name', $reliefEmployee->name)
            ->where('rows.0.relief_status', 'P2B Training')
            ->where('rows.0.readiness', 'in_progress')
            ->where('rows.0.attention.level', 'warning')
            ->where('rows.0.attention.label', 'Relief still in training')
            ->where('summary.relief_not_ready', 1)
        );
});

test('attention rules: relief joins late when planned join date is after sign-off', function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    $outgoing = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'assignment_no' => 'CA-OUTGOING-002',
        'planned_signoff_at' => '2026-10-15 00:00:00',
    ]);

    $reliefEmployee = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    makeCurrentCrewPhaseAssignment($company, $reliefEmployee, $rank, $vessel, CrewPhaseCode::ReadyToJoin, [
        'assignment_no' => 'CA-RELIEF-002',
        'relieves_crew_assignment_id' => $outgoing->id,
        'planned_join_at' => '2026-10-18 00:00:00', // 3 days late
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.readiness', 'at_risk')
            ->where('rows.0.attention.level', 'warning')
            ->where('rows.0.attention.label', 'Relief joins 3 days late')
        );
});

test('attention rules: relief ready (P3) with matching date is healthy', function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    $outgoing = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'assignment_no' => 'CA-OUTGOING-003',
        'planned_signoff_at' => '2026-10-25 00:00:00',
    ]);

    $reliefEmployee = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    makeCurrentCrewPhaseAssignment($company, $reliefEmployee, $rank, $vessel, CrewPhaseCode::ReadyToJoin, [
        'assignment_no' => 'CA-RELIEF-003',
        'relieves_crew_assignment_id' => $outgoing->id,
        'planned_join_at' => '2026-10-25 00:00:00',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.relief_status', 'P3 Ready to Join')
            ->where('rows.0.readiness', 'ready')
            ->where('rows.0.attention.level', 'healthy')
            ->where('rows.0.attention.label', 'Relief ready')
        );
});

test('attention rules: relief assignment conflict is flagged as critical', function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    $outgoing = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'assignment_no' => 'CA-OUTGOING-004',
        'planned_signoff_at' => '2026-10-20 00:00:00',
    ]);

    $reliefEmployee = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    // Incoming relief assignment
    makeCurrentCrewPhaseAssignment($company, $reliefEmployee, $rank, $vessel, CrewPhaseCode::ReadyToJoin, [
        'assignment_no' => 'CA-RELIEF-004',
        'relieves_crew_assignment_id' => $outgoing->id,
        'planned_join_at' => '2026-10-20 00:00:00',
    ]);

    // Another overlapping active assignment for the same relief employee
    $otherVessel = makeCrewMovementVessel('Other Conflicting Vessel', $company);
    makeActiveOnVesselAssignment($company, $reliefEmployee, $rank, $otherVessel, [
        'assignment_no' => 'CA-CONFLICT-001',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.readiness', 'at_risk')
            ->where('rows.0.attention.level', 'critical')
            ->where('rows.0.attention.label', 'Relief assignment conflict')
        );
});

test('next assignment indicates whether current crew member has another assignment planned', function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    $current = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'assignment_no' => 'CA-CURRENT-001',
        'planned_signoff_at' => '2026-10-20 00:00:00',
    ]);

    // Planned next assignment for same employee
    $nextVessel = makeCrewMovementVessel('Next Vessel', $company);
    CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-NEXT-001',
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $nextVessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'previous_assignment_id' => $current->id,
        'planned_join_at' => '2026-11-01 00:00:00',
        'source' => 'manual',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.next_assignment.assignment_no', 'CA-NEXT-001')
        );
});

test('inactive and terminated employees are excluded from operational lists', function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user, 'company' => $company, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    $inactive = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'inactive']);
    $terminated = Employee::factory()->forCompany($company)->terminated()->create(['rank_id' => $rank->id]);

    makeActiveOnVesselAssignment($company, $inactive, $rank, $vessel, [
        'assignment_no' => 'CA-INACTIVE-001',
        'planned_signoff_at' => '2026-10-15 00:00:00',
    ]);
    makeActiveOnVesselAssignment($company, $terminated, $rank, $vessel, [
        'assignment_no' => 'CA-TERMINATED-001',
        'planned_signoff_at' => '2026-10-15 00:00:00',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 0)
            ->where('summary.signing_off_next_7_days', 0)
        );
});

test('filters: can filter by vessel, readiness, attention, and search', function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user, 'company' => $company, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    $emp1 = Employee::factory()->forCompany($company)->create(['name' => 'John Doe', 'rank_id' => $rank->id, 'status' => 'active']);
    $emp2 = Employee::factory()->forCompany($company)->create(['name' => 'Jane Smith', 'rank_id' => $rank->id, 'status' => 'active']);

    $vessel2 = makeCrewMovementVessel('Other Filter Vessel', $company);

    makeActiveOnVesselAssignment($company, $emp1, $rank, $vessel, [
        'assignment_no' => 'CA-FILTER-001',
        'planned_signoff_at' => '2026-10-15 00:00:00',
    ]);
    makeActiveOnVesselAssignment($company, $emp2, $rank, $vessel2, [
        'assignment_no' => 'CA-FILTER-002',
        'planned_signoff_at' => '2026-10-25 00:00:00',
    ]);

    // Filter by vessel
    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index', ['vessel_id' => $vessel->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.assignment_no', 'CA-FILTER-001')
        );

    // Filter by search
    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index', ['search' => 'Jane Smith']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.assignment_no', 'CA-FILTER-002')
        );

    // Preset: overdue (neither is overdue)
    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index', ['preset' => 'overdue']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 0)
        );
});

test('crew relief report export downloads excel with active filters', function () {
    Excel::fake();
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'assignment_no' => 'CA-EXPORT-001',
        'planned_signoff_at' => '2026-10-15 00:00:00',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.export', [
            'format' => 'xlsx',
            'vessel_id' => $vessel->id,
        ]))
        ->assertOk();

    Excel::assertDownloaded('crew-relief-report-2026-10-10.xlsx', function (CrewReliefExport $export) {
        $rows = $export->collection();
        expect($rows)->toHaveCount(1)
            ->and($rows[0]['assignment_no'])->toBe('CA-EXPORT-001');

        $headings = $export->headings();
        expect($headings)->toContain('Current Crew', 'Rank', 'Vessel', 'Attention');

        $mapped = $export->map($rows[0]);
        expect($mapped[0])->toBe($rows[0]['employee']['name'])
            ->and($mapped[3])->toBe($rows[0]['vessel']['name']);

        return true;
    });
});

test('department restricted user cannot see assignments of employees from other departments', function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user, 'company' => $company, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    $deptDeck = Department::query()->create(['company_id' => $company->id, 'name' => 'Deck', 'code' => 'DCK', 'status' => 'active']);
    $deptEngine = Department::query()->create(['company_id' => $company->id, 'name' => 'Engine', 'code' => 'ENG', 'status' => 'active']);

    $deckEmp = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'department_id' => $deptDeck->id,
        'status' => 'active',
    ]);
    $engineEmp = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'department_id' => $deptEngine->id,
        'status' => 'active',
    ]);

    makeActiveOnVesselAssignment($company, $deckEmp, $rank, $vessel, [
        'assignment_no' => 'CA-DECK-001',
        'planned_signoff_at' => '2026-10-15 00:00:00',
    ]);
    makeActiveOnVesselAssignment($company, $engineEmp, $rank, $vessel, [
        'assignment_no' => 'CA-ENGINE-001',
        'planned_signoff_at' => '2026-10-15 00:00:00',
    ]);

    // Restrict user to Deck department only
    restrictUserToDepartments($user, $company, [$deptDeck->id]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.assignment_no', 'CA-DECK-001')
            ->where('summary.signing_off_next_7_days', 1)
        );
});

test('department restricted user sees redacted name for relief crew in inaccessible department', function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user, 'company' => $company, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    $deptDeck = Department::query()->create(['company_id' => $company->id, 'name' => 'Deck 2', 'code' => 'DCK2', 'status' => 'active']);
    $deptEngine = Department::query()->create(['company_id' => $company->id, 'name' => 'Engine 2', 'code' => 'ENG2', 'status' => 'active']);

    $deckEmp = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'department_id' => $deptDeck->id,
        'status' => 'active',
    ]);
    $engineRelief = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'department_id' => $deptEngine->id,
        'status' => 'active',
    ]);

    $outgoing = makeActiveOnVesselAssignment($company, $deckEmp, $rank, $vessel, [
        'assignment_no' => 'CA-DECK-OUTGOING',
        'planned_signoff_at' => '2026-10-25 00:00:00',
    ]);

    makeCurrentCrewPhaseAssignment($company, $engineRelief, $rank, $vessel, CrewPhaseCode::ReadyToJoin, [
        'assignment_no' => 'CA-ENGINE-RELIEF',
        'relieves_crew_assignment_id' => $outgoing->id,
        'planned_join_at' => '2026-10-25 00:00:00',
    ]);

    // Restrict user to Deck department only
    restrictUserToDepartments($user, $company, [$deptDeck->id]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.assignment_no', 'CA-DECK-OUTGOING')
            ->where('rows.0.relief_employee', null)
        );
});

test('quick presets correctly filter upcoming periods, no relief, and not ready', function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user, 'company' => $company, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    // 1: Signs off in 5 days, no relief (critical)
    $emp1 = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    makeActiveOnVesselAssignment($company, $emp1, $rank, $vessel, [
        'assignment_no' => 'CA-PRESET-5DAYS',
        'planned_signoff_at' => '2026-10-15 00:00:00',
    ]);

    // 2: Signs off in 10 days, has relief in training (warning)
    $emp2 = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    $outgoing2 = makeActiveOnVesselAssignment($company, $emp2, $rank, $vessel, [
        'assignment_no' => 'CA-PRESET-10DAYS',
        'planned_signoff_at' => '2026-10-20 00:00:00',
    ]);
    $relief2 = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    makeCurrentCrewPhaseAssignment($company, $relief2, $rank, $vessel, CrewPhaseCode::Training, [
        'assignment_no' => 'CA-RELIEF-TRAIN',
        'relieves_crew_assignment_id' => $outgoing2->id,
        'planned_join_at' => '2026-10-20 00:00:00',
    ]);

    // 3: Signs off in 25 days, has relief ready in P3 (healthy)
    $emp3 = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    $outgoing3 = makeActiveOnVesselAssignment($company, $emp3, $rank, $vessel, [
        'assignment_no' => 'CA-PRESET-25DAYS',
        'planned_signoff_at' => '2026-11-04 00:00:00',
    ]);
    $relief3 = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    makeCurrentCrewPhaseAssignment($company, $relief3, $rank, $vessel, CrewPhaseCode::ReadyToJoin, [
        'assignment_no' => 'CA-RELIEF-READY',
        'relieves_crew_assignment_id' => $outgoing3->id,
        'planned_join_at' => '2026-11-04 00:00:00',
    ]);

    // Preset: next_7_days (matches emp1 only)
    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index', ['preset' => 'next_7_days']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.assignment_no', 'CA-PRESET-5DAYS')
        );

    // Preset: next_14_days (matches emp1 and emp2)
    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index', ['preset' => 'next_14_days']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 2)
        );

    // Preset: no_relief (matches emp1)
    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index', ['preset' => 'no_relief']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.assignment_no', 'CA-PRESET-5DAYS')
        );

    // Preset: not_ready (matches emp2 in training)
    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index', ['preset' => 'not_ready']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.assignment_no', 'CA-PRESET-10DAYS')
        );
});

test('cross company relief assignment cannot be linked or viewed', function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    $outgoing = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'assignment_no' => 'CA-COMPANY-A-OUTGOING',
        'planned_signoff_at' => '2026-10-25 00:00:00',
    ]);

    ['company' => $otherCompany, 'employee' => $foreignEmployee, 'rank' => $foreignRank] = makeCrewAssignmentFixtures();
    $foreignVessel = makeCrewMovementVessel('Foreign Vessel', $otherCompany);

    // Create a relief assignment in Company B that attempts to relieve Company A's assignment
    makeCurrentCrewPhaseAssignment($otherCompany, $foreignEmployee, $foreignRank, $foreignVessel, CrewPhaseCode::ReadyToJoin, [
        'assignment_no' => 'CA-COMPANY-B-RELIEF',
        'relieves_crew_assignment_id' => $outgoing->id,
        'planned_join_at' => '2026-10-25 00:00:00',
    ]);

    // When querying Company A's report, foreign relief should NOT be linked or exposed
    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.assignment_no', 'CA-COMPANY-A-OUTGOING')
            ->where('rows.0.readiness', 'not_assigned')
            ->where('rows.0.relief_employee', null)
        );
});

test('conflict detection prevents mixed model numeric ID collision between crew assignments and planning assignments', function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user, 'company' => $company, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    $outgoing1 = makeActiveOnVesselAssignment($company, Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']), $rank, $vessel, [
        'assignment_no' => 'CA-OUTGOING-COLLIDE-1',
        'planned_signoff_at' => '2026-10-25 00:00:00',
    ]);

    $outgoing2 = makeActiveOnVesselAssignment($company, Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']), $rank, $vessel, [
        'assignment_no' => 'CA-OUTGOING-COLLIDE-2',
        'planned_signoff_at' => '2026-10-25 00:00:00',
    ]);

    $collisionId = 7777;

    // Relief 1: CrewAssignment with id = 7777 for outgoing1
    $reliefEmp1 = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    $reliefAssignment = CrewAssignment::query()->forceCreate([
        'id' => $collisionId,
        'company_id' => $company->id,
        'assignment_no' => 'CA-RELIEF-COLLISION',
        'employee_id' => $reliefEmp1->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Planned,
        'relieves_crew_assignment_id' => $outgoing1->id,
        'planned_join_at' => '2026-10-25 00:00:00',
        'planned_signoff_at' => '2026-11-25 00:00:00',
        'source' => 'manual',
    ]);

    // Give reliefEmp1 an overlapping competing active assignment on another vessel -> HAS CONFLICT!
    $otherVessel = makeCrewMovementVessel('Competing Active Vessel', $company);
    makeActiveOnVesselAssignment($company, $reliefEmp1, $rank, $otherVessel, [
        'assignment_no' => 'CA-COMPETING-ACTIVE',
    ]);

    // Relief 2: CrewPlanningAssignment with identical numeric id = 7777 for outgoing2
    $reliefEmp2 = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    $planningAssignment = CrewPlanningAssignment::query()->forceCreate([
        'id' => $collisionId,
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $reliefEmp2->id,
        'relieves_crew_assignment_id' => $outgoing2->id,
        'planned_join_date' => '2026-10-25',
        'planned_leave_date' => '2026-11-25',
    ]);

    // Verify IDs match to guarantee test tests collision
    expect($reliefAssignment->id)->toBe($collisionId)
        ->and($planningAssignment->id)->toBe($collisionId)
        ->and(CrewReliefPlanKey::for($reliefAssignment))->toBe("assignment:{$collisionId}")
        ->and(CrewReliefPlanKey::for($planningAssignment))->toBe("planning:{$collisionId}");

    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 2)
            // Outgoing 1 relief has conflict -> At Risk
            ->where('rows.0.assignment_no', 'CA-OUTGOING-COLLIDE-1')
            ->where('rows.0.readiness', 'at_risk')
            ->where('rows.0.attention.level', 'critical')
            ->where('rows.0.attention.label', 'Relief assignment conflict')
            // Outgoing 2 relief (planning with same id 7777) does NOT have conflict -> In Progress (not affected!)
            ->where('rows.1.assignment_no', 'CA-OUTGOING-COLLIDE-2')
            ->where('rows.1.readiness', 'in_progress')
            ->where('rows.1.attention.label', 'Relief in progress')
        );
});

test('conflict detection identifies competing assignment for CrewPlanningAssignment relief', function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user, 'company' => $company, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    $outgoing = makeActiveOnVesselAssignment($company, Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']), $rank, $vessel, [
        'assignment_no' => 'CA-OUTGOING-PLANNING-CONFLICT',
        'planned_signoff_at' => '2026-10-25 00:00:00',
    ]);

    $reliefEmp = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $reliefEmp->id,
        'relieves_crew_assignment_id' => $outgoing->id,
        'planned_join_date' => '2026-10-25',
        'planned_leave_date' => '2026-11-25',
    ]);

    // Give reliefEmp an overlapping competing planned assignment
    $otherVessel = makeCrewMovementVessel('Other Competing Planned Vessel', $company);
    CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-COMPETING-PLANNED',
        'employee_id' => $reliefEmp->id,
        'rank_id' => $rank->id,
        'vessel_id' => $otherVessel->id,
        'status' => CrewAssignmentStatus::Planned,
        'planned_join_at' => '2026-10-20 00:00:00',
        'planned_signoff_at' => '2026-11-20 00:00:00',
        'source' => 'manual',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.assignment_no', 'CA-OUTGOING-PLANNING-CONFLICT')
            ->where('rows.0.readiness', 'at_risk')
            ->where('rows.0.attention.level', 'critical')
            ->where('rows.0.attention.label', 'Relief assignment conflict')
        );
});

test('pagination architecture hydrates only page items and preserves urgency sorting across boundaries', function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user, 'company' => $company, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    // Create 30 assignments:
    // Assignment 0: Overdue sign-off (critical, urgency 1) with relief assigned
    // Assignment 1: No relief within 7 days (critical, urgency 1)
    // Assignments 2..29: Healthy relief in 22 days (urgency 5)
    $overdueOutgoing = makeActiveOnVesselAssignment($company, Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']), $rank, $vessel, [
        'assignment_no' => 'CA-PAGE-OVERDUE',
        'planned_signoff_at' => '2026-10-05 00:00:00', // 5 days overdue
    ]);
    $overdueRelief = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    makeCurrentCrewPhaseAssignment($company, $overdueRelief, $rank, $vessel, CrewPhaseCode::ReadyToJoin, [
        'assignment_no' => 'CA-RELIEF-OVERDUE',
        'relieves_crew_assignment_id' => $overdueOutgoing->id,
        'planned_join_at' => '2026-10-05 00:00:00',
    ]);

    makeActiveOnVesselAssignment($company, Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']), $rank, $vessel, [
        'assignment_no' => 'CA-PAGE-NORELIEF-URGENT',
        'planned_signoff_at' => '2026-10-14 00:00:00', // 4 days away, no relief
    ]);

    for ($i = 2; $i < 30; $i++) {
        $emp = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
        $outgoing = makeActiveOnVesselAssignment($company, $emp, $rank, $vessel, [
            'assignment_no' => sprintf('CA-PAGE-%03d', $i),
            'planned_signoff_at' => '2026-11-01 00:00:00',
        ]);

        $relief = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
        makeCurrentCrewPhaseAssignment($company, $relief, $rank, $vessel, CrewPhaseCode::ReadyToJoin, [
            'assignment_no' => sprintf('CA-RELIEF-%03d', $i),
            'relieves_crew_assignment_id' => $outgoing->id,
            'planned_join_at' => '2026-11-01 00:00:00',
        ]);
    }

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBeGreaterThanOrEqual(30);

    // Page 1: 25 items, critical items first
    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index', ['page' => 1, 'per_page' => 25]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 25)
            ->where('pagination.total', 30)
            ->where('pagination.current_page', 1)
            ->where('pagination.last_page', 2)
            ->where('pagination.per_page', 25)
            ->where('pagination.from', 1)
            ->where('pagination.to', 25)
            // Critical overdue item is first
            ->where('rows.0.assignment_no', 'CA-PAGE-OVERDUE')
            ->where('rows.0.attention.level', 'critical')
            // Second item is urgent no relief
            ->where('rows.1.assignment_no', 'CA-PAGE-NORELIEF-URGENT')
            ->where('rows.1.attention.level', 'critical')
            // Summary cards reflect full 30 candidate scope, not merely page 1
            ->where('summary.overdue_signoffs', 1)
            ->where('summary.no_relief_assigned', 1)
        );

    // Page 2: remaining 5 items
    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index', ['page' => 2, 'per_page' => 25]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 5)
            ->where('pagination.total', 30)
            ->where('pagination.current_page', 2)
            ->where('pagination.last_page', 2)
            ->where('pagination.from', 26)
            ->where('pagination.to', 30)
            ->where('rows.0.attention.level', 'healthy')
        );
});

test('date filters are safely validated and malformed dates do not cause 500 error', function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user] = authorizeCrewReliefReport();

    // Invalid planned_signoff_from -> 302 redirect with validation error
    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index', ['planned_signoff_from' => 'not-a-date']))
        ->assertRedirect()
        ->assertSessionHasErrors('planned_signoff_from');

    // Invalid planned_signoff_to -> 302 redirect with validation error
    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index', ['planned_signoff_to' => 'malformed-date']))
        ->assertRedirect()
        ->assertSessionHasErrors('planned_signoff_to');

    // planned_signoff_to before planned_signoff_from -> 302 redirect with validation error
    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index', [
            'planned_signoff_from' => '2026-10-25',
            'planned_signoff_to' => '2026-10-10',
        ]))
        ->assertRedirect()
        ->assertSessionHasErrors('planned_signoff_to');

    // Invalid enum filters -> redirect with validation error
    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index', ['preset' => 'invalid_preset']))
        ->assertRedirect()
        ->assertSessionHasErrors('preset');
});

test('search by remarks returns matching assignments', function () {
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user, 'company' => $company, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    $emp1 = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);
    $emp2 = Employee::factory()->forCompany($company)->create(['rank_id' => $rank->id, 'status' => 'active']);

    makeActiveOnVesselAssignment($company, $emp1, $rank, $vessel, [
        'assignment_no' => 'CA-REMARKS-MATCH',
        'planned_signoff_at' => '2026-10-25 00:00:00',
        'remarks' => 'Urgent drydock safety relief inspection',
    ]);

    makeActiveOnVesselAssignment($company, $emp2, $rank, $vessel, [
        'assignment_no' => 'CA-REMARKS-OTHER',
        'planned_signoff_at' => '2026-10-25 00:00:00',
        'remarks' => 'Routine vessel transfer planned next month',
    ]);

    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index', ['search' => 'drydock']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.assignment_no', 'CA-REMARKS-MATCH')
        );
});

test('hidden relief employee operational metadata is privacy safe in screen payload and export', function () {
    Excel::fake();
    CarbonImmutable::setTestNow('2026-10-10 12:00:00');

    ['user' => $user, 'company' => $company, 'rank' => $rank, 'vessel' => $vessel] = authorizeCrewReliefReport();

    $deptAllowed = Department::query()->create(['company_id' => $company->id, 'name' => 'Deck Allowed', 'code' => 'DCKA', 'status' => 'active']);
    $deptHidden = Department::query()->create(['company_id' => $company->id, 'name' => 'Engine Hidden', 'code' => 'ENGH', 'status' => 'active']);

    $outgoingEmp = Employee::factory()->forCompany($company)->create([
        'name' => 'Allowed Captain',
        'employee_no' => 'EMP-ALLOW-01',
        'rank_id' => $rank->id,
        'department_id' => $deptAllowed->id,
        'status' => 'active',
    ]);

    $hiddenReliefEmp = Employee::factory()->forCompany($company)->create([
        'name' => 'Secret Relief Chief',
        'employee_no' => 'EMP-SECRET-99',
        'rank_id' => $rank->id,
        'department_id' => $deptHidden->id,
        'status' => 'active',
    ]);

    $outgoing = makeActiveOnVesselAssignment($company, $outgoingEmp, $rank, $vessel, [
        'assignment_no' => 'CA-PRIVACY-OUTGOING',
        'planned_signoff_at' => '2026-10-20 00:00:00',
    ]);

    // Hidden relief is in training (P2B), joining late
    makeCurrentCrewPhaseAssignment($company, $hiddenReliefEmp, $rank, $vessel, CrewPhaseCode::Training, [
        'assignment_no' => 'CA-SECRET-RELIEF',
        'relieves_crew_assignment_id' => $outgoing->id,
        'planned_join_at' => '2026-10-23 00:00:00',
    ]);

    // Restrict user to Deck Allowed department
    restrictUserToDepartments($user, $company, [$deptAllowed->id]);

    // 1. Screen payload verification: no leakage of name, number, phase, join date, or internal details
    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 1)
            ->where('rows.0.assignment_no', 'CA-PRIVACY-OUTGOING')
            ->where('rows.0.employee.name', 'Allowed Captain')
            ->where('rows.0.relief_employee', null)
            ->where('rows.0.relief_status', 'Restricted')
            ->where('rows.0.relief_phase_code', null)
            ->where('rows.0.relief_planned_join', null)
            ->where('rows.0.readiness', 'restricted')
            ->where('rows.0.readiness_label', 'Restricted')
            ->where('rows.0.attention.badge', 'Relief requires attention')
        );

    // 2. Search verification: searching for hidden relief employee does not leak record
    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.index', ['search' => 'Secret Relief Chief']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('rows', 0)
        );

    // 3. Export verification: export preserves exact same redactions without leakage
    $this->actingAs($user)
        ->get(route('organization.reports.crew-relief.export', ['format' => 'xlsx']))
        ->assertOk();

    Excel::assertDownloaded('crew-relief-report-2026-10-10.xlsx', function (CrewReliefExport $export) {
        $rows = $export->collection();
        expect($rows)->toHaveCount(1);

        $mapped = $export->map($rows[0]);
        // Current Crew
        expect($mapped[0])->toBe('Allowed Captain');
        // Relief Crew is redacted as 'Restricted'
        expect($mapped[9])->toBe('Restricted');
        // Relief Status is 'Restricted'
        expect($mapped[10])->toBe('Restricted');
        // Relief Planned Join is '—'
        expect($mapped[11])->toBe('—');
        // Readiness is 'Restricted'
        expect($mapped[12])->toBe('Restricted');
        // Attention is 'Relief requires attention'
        expect($mapped[14])->toBe('Relief requires attention');

        return true;
    });
});
