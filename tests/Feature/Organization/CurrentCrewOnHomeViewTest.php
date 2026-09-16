<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\Vessel;
use App\Support\CrewMovements\CrewMovementAttentionQuery;
use App\Support\CrewMovements\CurrentCrewHomeQuery;
use App\Support\CrewMovements\CurrentCrewQuery;
use App\Support\CrewMovements\CurrentCrewRequestFilters;
use App\Support\CrewOperations\CrewOperationsSettings;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array{company: Company, employee: Employee, rank: Rank, vessel: Vessel}  $fixtures
 */
function excludeFixtureEmployeeFromHomePool(array $fixtures): void
{
    makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::JoinStandby,
    );
}

/**
 * @return array{assignment: CrewAssignment}
 */
function makeCompletedHomeAssignment(
    $company,
    Employee $employee,
    $rank,
    $vessel,
    int $closedDaysAgo = 5,
): array {
    $closedAt = CarbonImmutable::today($company->timezone ?? 'UTC')->subDays($closedDaysAgo);

    $assignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-HOME-'.fake()->unique()->numerify('######'),
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Completed,
        'started_at' => $closedAt->subDays(30),
        'closed_at' => $closedAt,
        'source' => 'manual',
    ]);

    return ['assignment' => $assignment];
}

test('active p6 crew appears in on home pool', function () {
    $fixtures = makeCurrentCrewVesselViewFixtures();
    excludeFixtureEmployeeFromHomePool($fixtures);
    $employee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
    ]);

    makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        $employee,
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::HomeRedeploy,
    );

    $paginator = CurrentCrewHomeQuery::paginate($fixtures['company']->id, []);

    expect($paginator->total())->toBe(1)
        ->and($paginator->items()[0]['days_at_home'])->toBeGreaterThanOrEqual(0);
});

test('completed latest assignment crew remains in on home pool', function () {
    $fixtures = makeCurrentCrewVesselViewFixtures();

    makeCompletedHomeAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
        closedDaysAgo: 12,
    );

    $paginator = CurrentCrewHomeQuery::paginate($fixtures['company']->id, []);

    expect($paginator->total())->toBe(1)
        ->and($paginator->items()[0]['days_at_home'])->toBe(12);
});

test('employee with newer active assignment is excluded from on home pool', function () {
    $fixtures = makeCurrentCrewVesselViewFixtures();
    excludeFixtureEmployeeFromHomePool($fixtures);

    makeCompletedHomeAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
    );

    makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::JoinStandby,
    );

    expect(CurrentCrewHomeQuery::paginate($fixtures['company']->id, [])->total())->toBe(0);
});

test('employee with newer draft assignment is excluded from on home pool', function () {
    $fixtures = makeCurrentCrewVesselViewFixtures();
    excludeFixtureEmployeeFromHomePool($fixtures);

    makeCompletedHomeAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
    );

    CrewAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'assignment_no' => 'CA-DRAFT-HOME',
        'employee_id' => $fixtures['employee']->id,
        'rank_id' => $fixtures['rank']->id,
        'vessel_id' => $fixtures['vessel']->id,
        'status' => CrewAssignmentStatus::Draft,
        'source' => 'manual',
    ]);

    expect(CurrentCrewHomeQuery::paginate($fixtures['company']->id, [])->total())->toBe(0);
});

test('inactive employee is excluded from on home pool', function () {
    $fixtures = makeCurrentCrewVesselViewFixtures();
    excludeFixtureEmployeeFromHomePool($fixtures);
    $inactive = Employee::factory()->forCompany($fixtures['company'])->inactive()->create([
        'rank_id' => $fixtures['rank']->id,
    ]);

    makeCompletedHomeAssignment(
        $fixtures['company'],
        $inactive,
        $fixtures['rank'],
        $fixtures['vessel'],
    );

    expect(CurrentCrewHomeQuery::paginate($fixtures['company']->id, [])->total())->toBe(0);
});

test('foreign company employee never appears in on home pool', function () {
    $fixtures = makeCurrentCrewVesselViewFixtures();
    $foreign = makeCurrentCrewVesselViewFixtures();

    makeCompletedHomeAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
    );

    makeCompletedHomeAssignment(
        $foreign['company'],
        $foreign['employee'],
        $foreign['rank'],
        $foreign['vessel'],
    );

    expect(CurrentCrewHomeQuery::paginate($fixtures['company']->id, [])->total())->toBe(1);
});

test('days at home is calculated from completed assignment closed at', function () {
    $fixtures = makeCurrentCrewVesselViewFixtures();

    makeCompletedHomeAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
        closedDaysAgo: 18,
    );

    $item = CurrentCrewHomeQuery::paginate($fixtures['company']->id, [])->items()[0];

    expect($item['days_at_home'])->toBe(18);
});

test('max home days comes from crew operations settings', function () {
    $fixtures = makeCurrentCrewVesselViewFixtures();
    CrewOperationsSettings::saveSettings($fixtures['company']->id, [], 45);

    makeCompletedHomeAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
        closedDaysAgo: 10,
    );

    $summary = CurrentCrewHomeQuery::summaryCounts($fixtures['company']->id);

    expect($summary['max_home_days'])->toBe(45);
});

test('over limit status uses days at home greater than max home days', function () {
    $fixtures = makeCurrentCrewVesselViewFixtures();
    CrewOperationsSettings::saveSettings($fixtures['company']->id, [], 30);

    makeCompletedHomeAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
        closedDaysAgo: 36,
    );

    $summary = CurrentCrewHomeQuery::summaryCounts($fixtures['company']->id);

    expect($summary['on_home'])->toBe(1)
        ->and($summary['on_home_over_limit'])->toBe(1);
});

test('different companies can have different home limits in summary', function () {
    $fixtures = makeCurrentCrewVesselViewFixtures();
    $other = makeCurrentCrewVesselViewFixtures();

    CrewOperationsSettings::saveSettings($fixtures['company']->id, [], 30);
    CrewOperationsSettings::saveSettings($other['company']->id, [], 60);

    expect(CurrentCrewHomeQuery::summaryCounts($fixtures['company']->id)['max_home_days'])->toBe(30)
        ->and(CurrentCrewHomeQuery::summaryCounts($other['company']->id)['max_home_days'])->toBe(60);
});

test('summary card totals include on home and over limit counts', function () {
    $fixtures = makeCurrentCrewVesselViewFixtures();
    excludeFixtureEmployeeFromHomePool($fixtures);
    CrewOperationsSettings::saveSettings($fixtures['company']->id, [], 30);

    $overLimit = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'name' => 'Over Limit Crew',
    ]);
    makeCompletedHomeAssignment(
        $fixtures['company'],
        $overLimit,
        $fixtures['rank'],
        $fixtures['vessel'],
        closedDaysAgo: 36,
    );

    $withinLimit = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'name' => 'Within Limit Crew',
    ]);
    makeCompletedHomeAssignment(
        $fixtures['company'],
        $withinLimit,
        $fixtures['rank'],
        $fixtures['vessel'],
        closedDaysAgo: 10,
    );

    $summary = CrewMovementAttentionQuery::summaryCounts($fixtures['company']->id);

    expect($summary['on_home'])->toBe(2)
        ->and($summary['on_home_over_limit'])->toBe(1)
        ->and($summary['max_home_days'])->toBe(30);
});

test('view on home returns only on home crew via inertia', function () {
    $fixtures = makeCurrentCrewVesselViewFixtures();
    excludeFixtureEmployeeFromHomePool($fixtures);
    CrewOperationsSettings::saveSettings($fixtures['company']->id, [], 30);

    $homeEmployee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
    ]);
    makeCompletedHomeAssignment(
        $fixtures['company'],
        $homeEmployee,
        $fixtures['rank'],
        $fixtures['vessel'],
        closedDaysAgo: 8,
    );

    makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        Employee::factory()->forCompany($fixtures['company'])->create([
            'rank_id' => $fixtures['rank']->id,
        ]),
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::JoinStandby,
    );

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-assignments.index', ['view' => 'on_home']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'on_home')
            ->has('home_crew', 1)
            ->where('home_crew.0.employee.id', $homeEmployee->id)
            ->where('home_crew.0.days_at_home', 8)
            ->where('home_crew.0.max_home_days', 30)
            ->has('assignments', 0));
});

test('conflicting query parameters cannot turn on home view into historical assignment results', function () {
    $fixtures = makeCurrentCrewVesselViewFixtures();

    makeCompletedHomeAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
    );

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-assignments.index', [
            'view' => 'on_home',
            'status' => 'completed',
            'phase' => 'p0',
            'include_completed' => 1,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('view', 'on_home')
            ->where('filters.status', '')
            ->where('filters.phase', '')
            ->where('filters.include_completed', false)
            ->has('home_crew', 1));
});

test('on home search and pagination remain server authoritative', function () {
    $fixtures = makeCurrentCrewVesselViewFixtures();
    excludeFixtureEmployeeFromHomePool($fixtures);

    foreach (range(1, 16) as $index) {
        $employee = Employee::factory()->forCompany($fixtures['company'])->create([
            'rank_id' => $fixtures['rank']->id,
            'name' => "Home Crew {$index}",
        ]);
        makeCompletedHomeAssignment(
            $fixtures['company'],
            $employee,
            $fixtures['rank'],
            $fixtures['vessel'],
            closedDaysAgo: $index,
        );
    }

    $search = CurrentCrewHomeQuery::paginate($fixtures['company']->id, ['search' => 'Home Crew 16']);

    expect($search->total())->toBe(1)
        ->and($search->items()[0]['employee']->name)->toBe('Home Crew 16');

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-assignments.index', [
            'view' => 'on_home',
            'per_page' => 15,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('pagination.total', 16)
            ->where('pagination.per_page', 15)
            ->where('pagination.current_page', 1)
            ->has('home_crew', 15));
});

test('existing pre join hotel view remains unchanged when on home is added', function () {
    $fixtures = makeCurrentCrewVesselViewFixtures();

    makeCurrentCrewPhaseAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $fixtures['vessel'],
        CrewPhaseCode::JoinStandby,
    );

    $paginator = CurrentCrewQuery::paginate(
        $fixtures['company']->id,
        [],
        CurrentCrewRequestFilters::VIEW_PRE_JOIN_HOTEL,
    );

    expect($paginator->total())->toBe(1)
        ->and(CrewMovementAttentionQuery::summaryCounts($fixtures['company']->id)['pre_join_hotel'])->toBe(1);
});
