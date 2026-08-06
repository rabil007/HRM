<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\VesselManning;
use App\Support\CrewOperations\CrewProjectedManningQuery;
use App\Support\CrewPlanning\CreateCrewAssignmentFromPlanning;

it('excludes cross-company Vessel Manning assignments and Planning from projection', function () {
    $a = makeCrewAssignmentFixtures();
    $b = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Tenant Isolation Vessel');

    VesselManning::query()->create([
        'company_id' => $a['company']->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $a['rank']->id,
        'required_count' => 1,
    ]);
    VesselManning::query()->create([
        'company_id' => $b['company']->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $b['rank']->id,
        'required_count' => 5,
    ]);

    makeActiveOnVesselAssignment($b['company'], $b['employee'], $b['rank'], $vessel, [
        'planned_signoff_at' => '2026-08-20 00:00:00',
    ]);
    CrewPlanningAssignment::query()->create([
        'company_id' => $b['company']->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $b['rank']->id,
        'employee_id' => Employee::factory()->forCompany($b['company'])->create([
            'rank_id' => $b['rank']->id,
            'status' => 'active',
        ])->id,
        'planned_join_date' => '2026-08-10',
        'planned_leave_date' => '2026-11-10',
    ]);

    $result = (new CrewProjectedManningQuery)->forCompany(
        (int) $a['company']->id,
        '2026-08-01',
        '2026-08-31',
        (int) $vessel->id,
        (int) $a['rank']->id,
    );

    expect($result['items'])->toHaveCount(1)
        ->and($result['items'][0]['required_count'])->toBe(1)
        ->and($result['items'][0]['starting_count'])->toBe(0)
        ->and($result['items'][0]['events'])->toBeEmpty();
});

it('respects vessel and rank filters', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vesselA = makeCrewMovementVessel('Filter Vessel A');
    $vesselB = makeCrewMovementVessel('Filter Vessel B');
    $rankB = Rank::query()->create([
        'name' => 'Filter Rank B '.uniqid(),
        'is_active' => true,
    ]);

    VesselManning::query()->create([
        'company_id' => $fixtures['company']->id,
        'vessel_id' => $vesselA->id,
        'rank_id' => $fixtures['rank']->id,
        'required_count' => 1,
    ]);
    VesselManning::query()->create([
        'company_id' => $fixtures['company']->id,
        'vessel_id' => $vesselB->id,
        'rank_id' => $rankB->id,
        'required_count' => 3,
    ]);

    $all = (new CrewProjectedManningQuery)->forCompany(
        (int) $fixtures['company']->id,
        '2026-08-01',
        '2026-08-31',
    );
    $filtered = (new CrewProjectedManningQuery)->forCompany(
        (int) $fixtures['company']->id,
        '2026-08-01',
        '2026-08-31',
        (int) $vesselA->id,
        (int) $fixtures['rank']->id,
    );

    expect($all['items'])->toHaveCount(2)
        ->and($filtered['items'])->toHaveCount(1)
        ->and($filtered['items'][0]['vessel_id'])->toBe($vesselA->id)
        ->and($filtered['items'][0]['rank_id'])->toBe($fixtures['rank']->id);
});

it('does not project cancelled completed or P5/P6 historical linked relief as future joins', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Relief Vessel');

    VesselManning::query()->create([
        'company_id' => $fixtures['company']->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $fixtures['rank']->id,
        'required_count' => 1,
    ]);

    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
        ['planned_signoff_at' => '2026-08-20 00:00:00'],
    );

    $reliefEmployee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
    ]);
    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $fixtures['rank']->id,
        'employee_id' => $reliefEmployee->id,
        'relieves_crew_assignment_id' => $source->id,
        'planned_join_date' => '2026-08-20',
        'planned_leave_date' => '2026-11-20',
    ]);
    $linked = app(CreateCrewAssignmentFromPlanning::class)->handle($planning, $fixtures['user']->id);
    $linked->update(['status' => CrewAssignmentStatus::Active]);
    $linked->currentPhase->update([
        'phase_code' => CrewPhaseCode::OnVessel,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-06-01 08:00:00',
        'actual_end_at' => '2026-07-01 08:00:00',
    ]);
    CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $linked->id,
        'phase_code' => CrewPhaseCode::DemobStandby,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-07-01 09:00:00',
    ]);
    $linked->update([
        'current_phase_id' => CrewAssignmentPhase::query()
            ->where('crew_assignment_id', $linked->id)
            ->where('phase_code', CrewPhaseCode::DemobStandby)
            ->value('id'),
    ]);

    $result = (new CrewProjectedManningQuery)->forCompany(
        (int) $fixtures['company']->id,
        '2026-08-01',
        '2026-08-31',
        (int) $vessel->id,
        (int) $fixtures['rank']->id,
    );
    $item = $result['items'][0];

    expect(collect($item['events'])->where('crew_assignment_id', $linked->id))->toBeEmpty()
        ->and($item['status'])->toBe('future_gap');
});
