<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Company;
use App\Models\CrewAssignmentPhase;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Support\CrewMovements\CrewMovementAttentionQuery;
use App\Support\CrewMovements\CurrentCrewQuery;
use App\Support\CrewMovements\CurrentCrewRequestFilters;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     employee: Employee,
 *     rank: Rank,
 *     vessel: Vessel
 * }
 */
function makeOperationalViewsFixtures(): array
{
    return makeCurrentCrewVesselViewFixtures();
}

test('summary counts map operational location cards from active current phases', function () {
    $fixtures = makeOperationalViewsFixtures();
    $company = $fixtures['company'];

    $phases = [
        CrewPhaseCode::PreMobilisation,
        CrewPhaseCode::JoinStandby,
        CrewPhaseCode::Training,
        CrewPhaseCode::ReadyToJoin,
        CrewPhaseCode::OnVessel,
        CrewPhaseCode::DemobStandby,
        CrewPhaseCode::HomeRedeploy,
    ];

    foreach ($phases as $index => $phaseCode) {
        $employee = Employee::factory()->forCompany($company)->create([
            'rank_id' => $fixtures['rank']->id,
            'name' => "Phase Crew {$index}",
        ]);
        makeCurrentCrewPhaseAssignment(
            $company,
            $employee,
            $fixtures['rank'],
            $fixtures['vessel'],
            $phaseCode,
        );
    }

    $summary = CrewMovementAttentionQuery::summaryCounts($company->id);

    expect($summary['pre_join_hotel'])->toBe(3)
        ->and($summary['crew_on_site'])->toBe(1)
        ->and($summary['post_signoff_hotel'])->toBe(1)
        ->and($summary['by_phase']['p0'] ?? 0)->toBe(1)
        ->and($summary['by_phase']['p6'] ?? 0)->toBe(1);
});

test('summary counts exclude completed assignments from operational location cards', function () {
    $fixtures = makeOperationalViewsFixtures();
    $company = $fixtures['company'];

    $active = makeCurrentCrewPhaseAssignment(
        $company,
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::JoinStandby,
    );

    $completedEmployee = Employee::factory()->forCompany($company)->create([
        'rank_id' => $fixtures['rank']->id,
    ]);
    $completed = makeCurrentCrewPhaseAssignment(
        $company,
        $completedEmployee,
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::JoinStandby,
    );
    $completed->update(['status' => CrewAssignmentStatus::Completed]);

    $summary = CrewMovementAttentionQuery::summaryCounts($company->id);

    expect($summary['pre_join_hotel'])->toBe(1)
        ->and($active->fresh()->status)->toBe(CrewAssignmentStatus::Active);
});

test('pre join hotel view returns only active p2a p2b and p3 assignments', function (CrewPhaseCode $phaseCode, bool $included) {
    $fixtures = makeOperationalViewsFixtures();
    $employee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
    ]);

    makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        $employee,
        $fixtures['rank'],
        $fixtures['vessel'],
        $phaseCode,
    );

    $paginator = CurrentCrewQuery::paginate(
        $fixtures['company']->id,
        [],
        CurrentCrewRequestFilters::VIEW_PRE_JOIN_HOTEL,
    );

    expect($paginator->total())->toBe($included ? 1 : 0);
})->with([
    'p2a included' => [CrewPhaseCode::JoinStandby, true],
    'p2b included' => [CrewPhaseCode::Training, true],
    'p3 included' => [CrewPhaseCode::ReadyToJoin, true],
    'p0 excluded' => [CrewPhaseCode::PreMobilisation, false],
    'p1 excluded' => [CrewPhaseCode::TravelIn, false],
    'p4 excluded' => [CrewPhaseCode::OnVessel, false],
    'p5 excluded' => [CrewPhaseCode::DemobStandby, false],
    'p6 excluded' => [CrewPhaseCode::HomeRedeploy, false],
]);

test('pre join hotel view excludes completed historical p2a assignments', function () {
    $fixtures = makeOperationalViewsFixtures();
    $employee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
    ]);

    $assignment = makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        $employee,
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::JoinStandby,
    );
    $assignment->update(['status' => CrewAssignmentStatus::Completed]);

    $paginator = CurrentCrewQuery::paginate(
        $fixtures['company']->id,
        [],
        CurrentCrewRequestFilters::VIEW_PRE_JOIN_HOTEL,
    );

    expect($paginator->total())->toBe(0);
});

test('post signoff hotel view returns only active p5 assignments', function () {
    $fixtures = makeOperationalViewsFixtures();

    makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::DemobStandby,
    );

    $other = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
    ]);
    makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        $other,
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::OnVessel,
    );

    $paginator = CurrentCrewQuery::paginate(
        $fixtures['company']->id,
        [],
        CurrentCrewRequestFilters::VIEW_POST_SIGNOFF_HOTEL,
    );

    expect($paginator->total())->toBe(1)
        ->and($paginator->items()[0]->currentPhase?->phase_code)->toBe(CrewPhaseCode::DemobStandby);
});

test('post signoff hotel view excludes completed p5 assignments', function () {
    $fixtures = makeOperationalViewsFixtures();

    $assignment = makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::DemobStandby,
    );
    $assignment->update(['status' => CrewAssignmentStatus::Completed]);

    $paginator = CurrentCrewQuery::paginate(
        $fixtures['company']->id,
        [],
        CurrentCrewRequestFilters::VIEW_POST_SIGNOFF_HOTEL,
    );

    expect($paginator->total())->toBe(0);
});

test('operational hotel views ignore conflicting phase filters and include completed', function () {
    $fixtures = makeOperationalViewsFixtures();

    makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::JoinStandby,
    );

    $preJoin = CurrentCrewQuery::paginate(
        $fixtures['company']->id,
        ['phase' => 'p4', 'include_completed' => true],
        CurrentCrewRequestFilters::VIEW_PRE_JOIN_HOTEL,
    );

    $postSignoff = CurrentCrewQuery::paginate(
        $fixtures['company']->id,
        ['phase' => 'p4', 'include_completed' => true],
        CurrentCrewRequestFilters::VIEW_POST_SIGNOFF_HOTEL,
    );

    expect($preJoin->total())->toBe(1)
        ->and($postSignoff->total())->toBe(0);
});

test('pre join hotel search filters within the operational view', function () {
    $fixtures = makeOperationalViewsFixtures();

    makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::JoinStandby,
    );

    $other = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'name' => 'Ahmed Other',
    ]);
    makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        $other,
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::ReadyToJoin,
    );

    $paginator = CurrentCrewQuery::paginate(
        $fixtures['company']->id,
        ['search' => 'Ahmed'],
        CurrentCrewRequestFilters::VIEW_PRE_JOIN_HOTEL,
    );

    expect($paginator->total())->toBe(1)
        ->and($paginator->items()[0]->employee?->name)->toBe('Ahmed Other');
});

test('post signoff hotel search filters within the operational view', function () {
    $fixtures = makeOperationalViewsFixtures();

    makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::DemobStandby,
    );

    $other = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'name' => 'Ahmed Demob',
    ]);
    makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        $other,
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::DemobStandby,
    );

    $paginator = CurrentCrewQuery::paginate(
        $fixtures['company']->id,
        ['search' => 'Ahmed Demob'],
        CurrentCrewRequestFilters::VIEW_POST_SIGNOFF_HOTEL,
    );

    expect($paginator->total())->toBe(1)
        ->and($paginator->items()[0]->employee?->name)->toBe('Ahmed Demob');
});

test('pre join hotel pagination totals remain server authoritative', function () {
    $fixtures = makeOperationalViewsFixtures();

    foreach (range(1, 16) as $index) {
        $employee = Employee::factory()->forCompany($fixtures['company'])->create([
            'rank_id' => $fixtures['rank']->id,
            'name' => "Pre Join {$index}",
        ]);
        makeCurrentCrewPhaseAssignment(
            $fixtures['company'],
            $employee,
            $fixtures['rank'],
            $fixtures['vessel'],
            CrewPhaseCode::JoinStandby,
        );
    }

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-assignments.index', [
            'view' => 'pre_join_hotel',
            'per_page' => 15,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'pre_join_hotel')
            ->where('pagination.total', 16)
            ->where('pagination.per_page', 15)
            ->where('pagination.current_page', 1)
            ->where('pagination.last_page', 2)
            ->has('assignments', 15));

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-assignments.index', [
            'view' => 'pre_join_hotel',
            'per_page' => 15,
            'page' => 2,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 16)
            ->where('pagination.current_page', 2)
            ->has('assignments', 1));
});

test('operational views remain company scoped', function () {
    $fixtures = makeOperationalViewsFixtures();
    $foreign = makeCurrentCrewVesselViewFixtures();

    makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::JoinStandby,
    );

    makeCurrentCrewPhaseAssignment(
        $foreign['company'],
        $foreign['employee'],
        $foreign['rank'],
        $foreign['vessel'],
        CrewPhaseCode::JoinStandby,
    );

    $summary = CrewMovementAttentionQuery::summaryCounts($fixtures['company']->id);
    $paginator = CurrentCrewQuery::paginate(
        $fixtures['company']->id,
        [],
        CurrentCrewRequestFilters::VIEW_PRE_JOIN_HOTEL,
    );

    expect($summary['pre_join_hotel'])->toBe(1)
        ->and($paginator->total())->toBe(1);
});

test('crew assignments index exposes operational summary cards and views', function () {
    $fixtures = makeOperationalViewsFixtures();

    makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::JoinStandby,
    );

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-assignments.index', ['view' => 'pre_join_hotel']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/index')
            ->where('view', 'pre_join_hotel')
            ->has('summary.pre_join_hotel')
            ->has('summary.crew_on_site')
            ->has('summary.post_signoff_hotel')
            ->has('assignments', 1));

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-assignments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'crew')
            ->has('assignments'));
});

test('unknown view query falls back to standard crew list', function () {
    $fixtures = makeOperationalViewsFixtures();

    makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::JoinStandby,
    );

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-assignments.index', ['view' => 'unknown_view']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'crew')
            ->has('assignments', 1));
});

test('completed history remains accessible through include completed on default crew view', function () {
    $fixtures = makeOperationalViewsFixtures();

    $assignment = makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::HomeRedeploy,
    );
    $assignment->update(['status' => CrewAssignmentStatus::Completed]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-assignments.index', [
            'include_completed' => 1,
            'status' => 'completed',
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'crew')
            ->has('assignments', 1));
});

test('include completed does not leak history into pre join hotel view', function () {
    $fixtures = makeOperationalViewsFixtures();

    $completed = makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::JoinStandby,
    );
    $completed->update(['status' => CrewAssignmentStatus::Completed]);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-assignments.index', [
            'view' => 'pre_join_hotel',
            'include_completed' => 1,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'pre_join_hotel')
            ->has('assignments', 0));
});

test('historical p5 phase on completed assignment does not count as post signoff hotel', function () {
    $fixtures = makeOperationalViewsFixtures();

    $assignment = makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::HomeRedeploy,
    );

    CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::DemobStandby,
        'sequence' => 0,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => now()->subDays(10),
        'actual_end_at' => now()->subDays(5),
    ]);

    $assignment->update(['status' => CrewAssignmentStatus::Completed]);

    expect(CrewMovementAttentionQuery::summaryCounts($fixtures['company']->id)['post_signoff_hotel'])->toBe(0);
});
