<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exports\CrewReliefExport;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Vessel;
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
