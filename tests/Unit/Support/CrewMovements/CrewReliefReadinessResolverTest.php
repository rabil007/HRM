<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewReliefRisk;
use App\Enums\CrewReliefStatus;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Support\CrewMovements\CrewReliefReadinessResolver;
use App\Support\CrewPlanning\CreateCrewAssignmentFromPlanning;

it('marks no relief within 14 days as warning risk', function () {
    $resolver = new CrewReliefReadinessResolver;

    expect($resolver->riskFor(CrewReliefStatus::NoRelief, 10))
        ->toBe(CrewReliefRisk::Warning);
});

it('marks mobilising within 7 days as critical risk', function () {
    $resolver = new CrewReliefReadinessResolver;

    expect($resolver->riskFor(CrewReliefStatus::Mobilising, 5))
        ->toBe(CrewReliefRisk::Critical);
});

it('marks ready to join as none risk', function () {
    $resolver = new CrewReliefReadinessResolver;

    expect($resolver->riskFor(CrewReliefStatus::ReadyToJoin, 3))
        ->toBe(CrewReliefRisk::None);
});

it('treats active P5 and P6 linked relief as non-operational', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $source = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Unit P5 Vessel'),
    );
    $reliefEmployee = Employee::factory()->forCompany($fixtures['company'])->create([
        'rank_id' => $fixtures['rank']->id,
        'status' => 'active',
    ]);
    $plan = CrewPlanningAssignment::query()->create([
        'company_id' => $fixtures['company']->id,
        'vessel_id' => $source->vessel_id,
        'rank_id' => $source->rank_id,
        'employee_id' => $reliefEmployee->id,
        'relieves_crew_assignment_id' => $source->id,
        'planned_join_date' => now()->addDays(5)->toDateString(),
        'planned_leave_date' => now()->addDays(90)->toDateString(),
    ]);
    $linked = app(CreateCrewAssignmentFromPlanning::class)
        ->handle($plan, $fixtures['user']->id);
    $linked->update(['status' => CrewAssignmentStatus::Active]);
    $linked->currentPhase->update([
        'phase_code' => CrewPhaseCode::DemobStandby,
        'status' => CrewPhaseStatus::Active,
    ]);

    $resolver = new CrewReliefReadinessResolver;
    $plan = $plan->fresh(['crewAssignment.currentPhase']);

    expect($resolver->isOperationallyActive($plan))->toBeFalse()
        ->and($resolver->forSourceAssignment($source->fresh())->status)->toBe(CrewReliefStatus::NoRelief);

    $linked->currentPhase->update([
        'phase_code' => CrewPhaseCode::HomeRedeploy,
    ]);
    $plan = $plan->fresh(['crewAssignment.currentPhase']);

    expect($resolver->isOperationallyActive($plan))->toBeFalse();
});
