<?php

use App\Enums\CrewMovementAction;
use App\Models\Company;
use App\Models\CrewOperationalAlert;
use App\Models\CrewPlanningAssignment;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Support\CrewMovements\CrewMovementAttentionQuery;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewOperations\CrewOperationsDashboardAnalytics;
use App\Support\CrewOperations\ReconcileCrewOperationalAlerts;
use App\Support\CrewOperations\ResolveCrewOperationalAlertUrl;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     marineDept: Department,
 *     officeDept: Department,
 *     marineEmployee: Employee,
 *     officeEmployee: Employee,
 *     rank: Rank,
 *     vessel: Vessel
 * }
 */
function makeCrewEmployeeVisibilityFixtures(): array
{
    $fixtures = makeEmployeeVisibilityFixtures();

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.update',
        'crew_operations.assignments.void',
        'crew_operations.movements.perform',
        'crew_operations.planning.view',
        'crew_operations.overview.view',
        'employees.view',
        'sea_services.delete',
        'training.delete',
    ]);

    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);
    $fixtures['rank'] = Rank::query()->create([
        'name' => 'Visibility Rank '.uniqid(),
        'is_active' => true,
    ]);
    $fixtures['marineEmployee']->update(['rank_id' => $fixtures['rank']->id]);
    $fixtures['officeEmployee']->update(['rank_id' => $fixtures['rank']->id]);
    $fixtures['vessel'] = makeCrewMovementVessel('Visibility Vessel', $fixtures['company']);

    return $fixtures;
}

test('current crew filter options exclude hidden employees for restricted roles', function () {
    [
        'user' => $user,
        'company' => $company,
        'marineDept' => $marineDept,
        'marineEmployee' => $marine,
        'officeEmployee' => $office,
        'rank' => $rank,
        'vessel' => $vessel,
    ] = makeCrewEmployeeVisibilityFixtures();

    makeActiveOnVesselAssignment($company, $marine, $rank, $vessel);
    makeActiveOnVesselAssignment($company, $office, $rank, $vessel);

    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filter_options.employees', fn ($employees) => collect($employees)->pluck('id')->all() === [$marine->id])
            ->where('assignments', fn ($assignments) => collect($assignments)->pluck('employee.id')->all() === [$marine->id]));
});

test('current crew summary counts exclude hidden employees for restricted roles', function () {
    [
        'user' => $user,
        'company' => $company,
        'marineDept' => $marineDept,
        'marineEmployee' => $marine,
        'officeEmployee' => $office,
        'rank' => $rank,
        'vessel' => $vessel,
    ] = makeCrewEmployeeVisibilityFixtures();

    makeActiveOnVesselAssignment($company, $marine, $rank, $vessel);
    makeActiveOnVesselAssignment($company, $office, $rank, $vessel);

    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $summary = CrewMovementAttentionQuery::summaryCounts($company->id, $user);

    expect($summary['total'])->toBe(1)
        ->and($summary['crew_on_site'])->toBe(1);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 1)
            ->where('summary.crew_on_site', 1));
});

test('unrestricted role still sees all crew employees in filters and summaries', function () {
    [
        'user' => $user,
        'company' => $company,
        'marineEmployee' => $marine,
        'officeEmployee' => $office,
        'rank' => $rank,
        'vessel' => $vessel,
    ] = makeCrewEmployeeVisibilityFixtures();

    makeActiveOnVesselAssignment($company, $marine, $rank, $vessel);
    makeActiveOnVesselAssignment($company, $office, $rank, $vessel);

    $summary = CrewMovementAttentionQuery::summaryCounts($company->id, $user);

    expect($summary['total'])->toBe(2)
        ->and($summary['crew_on_site'])->toBe(2);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.total', 2)
            ->where('filter_options.employees', fn ($employees) => collect($employees)->pluck('id')->sort()->values()->all()
                === collect([$marine->id, $office->id])->sort()->values()->all()));
});

test('crew operations dashboard excludes hidden employees from employee-linked cards', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 08:00:00', 'Asia/Dubai'));

    [
        'user' => $user,
        'company' => $company,
        'marineDept' => $marineDept,
        'marineEmployee' => $marine,
        'officeEmployee' => $office,
        'rank' => $rank,
        'vessel' => $vessel,
    ] = makeCrewEmployeeVisibilityFixtures();

    makeActiveOnVesselAssignment($company, $marine, $rank, $vessel, [
        'planned_signoff_at' => '2026-09-01 00:00:00',
    ]);
    makeActiveOnVesselAssignment($company, $office, $rank, $vessel, [
        'planned_signoff_at' => '2026-09-01 00:00:00',
    ]);

    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $dashboard = app(CrewOperationsDashboardAnalytics::class)->forCompany($company->id, $user);

    expect($dashboard['daily_pulse']['onboard_now'])->toBe(1)
        ->and($dashboard['daily_pulse']['signoffs_overdue'])->toBe(1);

    CarbonImmutable::setTestNow();
});

test('relief desk hides hidden relief employee identity while keeping source visible', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 08:00:00', 'Asia/Dubai'));

    [
        'user' => $user,
        'company' => $company,
        'marineDept' => $marineDept,
        'officeDept' => $officeDept,
        'marineEmployee' => $marine,
        'officeEmployee' => $office,
        'rank' => $rank,
        'vessel' => $vessel,
    ] = makeCrewEmployeeVisibilityFixtures();

    $marine->update(['department_id' => $marineDept->id]);
    $office->update(['department_id' => $officeDept->id, 'name' => 'Hidden Relief Person']);

    $source = makeActiveOnVesselAssignment($company, $marine, $rank, $vessel, [
        'planned_signoff_at' => CarbonImmutable::now('Asia/Dubai')->addDays(6)->toDateTimeString(),
    ]);

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $office->id,
        'relieves_crew_assignment_id' => $source->id,
        'planned_join_date' => CarbonImmutable::now('Asia/Dubai')->addDays(6)->toDateString(),
        'planned_leave_date' => CarbonImmutable::now('Asia/Dubai')->addDays(90)->toDateString(),
    ]);

    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', ['view' => 'relief']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('relief_desk.rows', 1)
            ->where('relief_desk.rows.0.employee.name', $marine->name)
            ->where('relief_desk.rows.0.relief_employee', null)
            ->where('relief_desk.rows.0.relief_planning_assignment_id', null)
            ->where('relief_desk.rows.0.relief_crew_assignment_id', null));

    CarbonImmutable::setTestNow();
});

test('hidden assignment movement action returns not found without validation leakage', function () {
    [
        'user' => $user,
        'company' => $company,
        'marineDept' => $marineDept,
        'officeEmployee' => $office,
        'rank' => $rank,
    ] = makeCrewEmployeeVisibilityFixtures();

    $hidden = app(CrewMovementService::class)->startAssignment($company->id, $office->id, [
        'rank_id' => $rank->id,
        'stage_started_at' => '2026-01-01 08:00:00',
    ], $user->id);

    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $hidden), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-01-02 08:00:00',
        ])
        ->assertNotFound()
        ->assertSessionDoesntHaveErrors(['action', 'occurred_at', 'next_phase', 'check_in_date']);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $hidden), [
            'action' => CrewMovementAction::JoinVessel->value,
            'occurred_at' => '2026-01-02 08:00:00',
            'vessel_id' => makeCrewMovementVessel('Hidden Action Vessel', $company)->id,
            'rank_id' => $rank->id,
        ])
        ->assertNotFound()
        ->assertSessionDoesntHaveErrors(['action', 'occurred_at', 'vessel_id', 'rank_id']);
});

test('hidden assignment update returns not found before field validation leakage', function () {
    [
        'user' => $user,
        'company' => $company,
        'marineDept' => $marineDept,
        'officeEmployee' => $office,
        'rank' => $rank,
    ] = makeCrewEmployeeVisibilityFixtures();

    $hidden = app(CrewMovementService::class)->createDraft($company->id, $office->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $this->actingAs($user)
        ->put(route('organization.crew-assignments.update', $hidden), [
            'planned_join_at' => '2099-01-01',
        ])
        ->assertNotFound()
        ->assertSessionDoesntHaveErrors(['planned_join_at', 'vessel_id', 'rank_id']);
});

test('hidden assignment void returns not found before cleanup validation leakage', function () {
    [
        'user' => $user,
        'company' => $company,
        'marineDept' => $marineDept,
        'officeEmployee' => $office,
        'rank' => $rank,
    ] = makeCrewEmployeeVisibilityFixtures();

    $hidden = app(CrewMovementService::class)->createDraft($company->id, $office->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.void', $hidden), [
            'void_reason' => 'Should never validate',
            'delete_sea_service' => true,
            'delete_training' => true,
        ])
        ->assertNotFound()
        ->assertSessionDoesntHaveErrors(['void_reason', 'delete_sea_service', 'delete_training']);
});

test('hidden planning assignment handoff returns not found', function () {
    [
        'user' => $user,
        'company' => $company,
        'marineDept' => $marineDept,
        'officeEmployee' => $office,
        'rank' => $rank,
        'vessel' => $vessel,
    ] = makeCrewEmployeeVisibilityFixtures();

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $office->id,
        'planned_join_date' => '2026-10-01',
        'planned_leave_date' => '2026-12-01',
    ]);

    restrictUserToDepartments($user, $company, [$marineDept->id]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.create', [
            'planning_assignment_id' => $planning->id,
        ]))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', [
            'planning_assignment_id' => $planning->id,
            'open_create' => 1,
        ]))
        ->assertNotFound();
});

test('restricted recipient does not receive hidden employee crew alert in feed or unread count', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-07 12:00:00', 'Asia/Dubai'));

    [
        'user' => $user,
        'company' => $company,
        'marineDept' => $marineDept,
        'officeEmployee' => $office,
        'rank' => $rank,
    ] = makeCrewEmployeeVisibilityFixtures();

    restrictUserToDepartments($user, $company, [$marineDept->id]);

    enableCrewNotificationsForUser((int) $company->id, (int) $user->id);

    $assignment = makeActiveOnVesselAssignment(
        $company,
        $office,
        $rank,
        makeCrewMovementVessel('Alert Hidden Vessel', $company),
        [
            'tour_of_duty_days' => 90,
            'planned_signoff_at' => '2026-08-01 00:00:00',
        ],
    );

    app(ReconcileCrewOperationalAlerts::class)->forCompany((int) $company->id);

    $this->actingAs($user)
        ->getJson(route('notifications.feed'))
        ->assertOk()
        ->assertJsonPath('unread_count', 0)
        ->assertJsonCount(0, 'items');

    $alert = CrewOperationalAlert::query()->firstOrFail();
    $url = app(ResolveCrewOperationalAlertUrl::class)->forUser($user, $alert);

    expect($url)->not->toBe(route('organization.crew-assignments.show', ['assignment' => $assignment->id]));

    CarbonImmutable::setTestNow();
});
