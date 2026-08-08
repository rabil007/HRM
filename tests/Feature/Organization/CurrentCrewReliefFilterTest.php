<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewPlannedSignoffSource;
use App\Enums\CrewReliefRisk;
use App\Enums\CrewReliefStatus;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Support\CrewMovements\CurrentCrewQuery;
use App\Support\CrewOperations\CrewOperationsDashboardAnalytics;
use App\Support\CrewPlanning\CreateCrewAssignmentFromPlanning;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

it('filters current crew by relief status no relief', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Filter Relief Vessel');
    $today = CarbonImmutable::now($fixtures['company']->timezone)->startOfDay();

    $noRelief = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
        [
            'tour_of_duty_days' => 90,
            'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
            'planned_signoff_at' => $today->addDays(10)->toDateTimeString(),
        ],
    );

    $reliefEmployee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
    ]);
    $plannedVessel = makeCrewMovementVessel('Filter Planned Relief Vessel');
    $withPlan = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $reliefEmployee,
        $fixtures['rank'],
        $plannedVessel,
        [
            'tour_of_duty_days' => 90,
            'planned_signoff_source' => CrewPlannedSignoffSource::TourOfDuty->value,
            'planned_signoff_at' => $today->addDays(20)->toDateTimeString(),
        ],
    );

    CrewPlanningAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'vessel_id' => $plannedVessel->id,
        'rank_id' => $fixtures['rank']->id,
        'employee_id' => Employee::factory()->forCompany($fixtures['company'])->create([
            'rank_id' => $fixtures['rank']->id,
            'status' => 'active',
        ])->id,
        'relieves_crew_assignment_id' => $withPlan->id,
        'planned_join_date' => $today->addDays(20)->toDateString(),
        'planned_leave_date' => $today->addDays(110)->toDateString(),
    ]);

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    $paginator = CurrentCrewQuery::paginate((int) $fixtures['company']->id, [
        'relief_status' => CrewReliefStatus::NoRelief->value,
    ]);

    expect($paginator->total())->toBe(1)
        ->and($paginator->items()[0]->id)->toBe($noRelief->id);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-assignments.index', [
            'relief_status' => CrewReliefStatus::NoRelief->value,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/index')
            ->where('filters.relief_status', CrewReliefStatus::NoRelief->value)
            ->has('assignments', 1)
            ->where('assignments.0.id', $noRelief->id)
        );
});

it('matches current crew relief filters to daily dashboard risk and action signals', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-06 12:00:00', 'UTC'));

    $fixtures = makeCrewAssignmentFixtures();
    $companyId = (int) $fixtures['company']->id;

    makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Dash No Relief'),
        ['planned_signoff_at' => '2026-08-12 00:00:00'],
    );

    $readyEmployee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
    ]);
    $readySource = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $readyEmployee,
        $fixtures['rank'],
        makeCrewMovementVessel('Dash Ready Source'),
        ['planned_signoff_at' => '2026-09-01 00:00:00'],
    );
    $reliefEmployee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
    ]);
    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $companyId,
        'vessel_id' => $readySource->vessel_id,
        'rank_id' => $readySource->rank_id,
        'employee_id' => $reliefEmployee->id,
        'relieves_crew_assignment_id' => $readySource->id,
        'planned_join_date' => '2026-09-01',
        'planned_leave_date' => '2026-12-01',
    ]);
    $linked = app(CreateCrewAssignmentFromPlanning::class)->handle($planning, $fixtures['user']->id);
    $linked->update(['status' => CrewAssignmentStatus::Active]);
    $linked->currentPhase->update([
        'phase_code' => CrewPhaseCode::ReadyToJoin,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => now(),
    ]);

    $within14NoRelief = CurrentCrewQuery::paginate($companyId, [
        'signoff_within_14_no_relief' => true,
    ])->total();
    $reliefNotReady = CurrentCrewQuery::paginate($companyId, [
        'relief_not_ready' => true,
    ])->total();
    $readyToJoin = CurrentCrewQuery::paginate($companyId, [
        'relief_status' => CrewReliefStatus::ReadyToJoin->value,
    ])->total();
    $criticalRelief = CurrentCrewQuery::paginate($companyId, [
        'relief_risk' => CrewReliefRisk::Critical->value,
    ])->total();

    $dashboard = app(CrewOperationsDashboardAnalytics::class)
        ->forCompany($companyId, $fixtures['user']);

    $actions = collect($dashboard['action_required']);
    $risks = collect($dashboard['manning_relief_risks']);

    expect($within14NoRelief)->toBe(1)
        ->and($readyToJoin)->toBe(1)
        ->and($dashboard)->not->toHaveKey('alert_counts')
        ->and($risks->contains(
            fn (array $item): bool => $item['kind'] === 'relief'
                && in_array($item['risk'], ['No relief', 'Critical relief'], true),
        ))->toBeTrue();

    if ($criticalRelief > 0) {
        expect($actions->contains(
            fn (array $item): bool => $item['type'] === 'critical_relief_risk',
        ) || $risks->contains(
            fn (array $item): bool => $item['kind'] === 'relief'
                && $item['risk'] === 'Critical relief',
        ))->toBeTrue();
    }

    // relief_not_ready includes NoRelief; daily cockpit surfaces that as a No-relief risk,
    // not the separate "relief not ready" imminent action (which excludes NoRelief).
    if ($reliefNotReady > 0) {
        expect($risks->contains(
            fn (array $item): bool => $item['kind'] === 'relief',
        ))->toBeTrue();
    }

    CarbonImmutable::setTestNow();
});
