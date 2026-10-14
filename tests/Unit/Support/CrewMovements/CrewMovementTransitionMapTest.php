<?php

use App\Enums\CrewPhaseCode;
use App\Support\CrewMovements\CrewMovementTransitionMap;

test('p0 can move to p1a but not backward', function () {
    expect(CrewMovementTransitionMap::canTransitionWithinAssignment(
        CrewPhaseCode::PreMobilisation,
        CrewPhaseCode::JoinStandby,
    ))->toBeTrue()
        ->and(CrewMovementTransitionMap::canTransitionWithinAssignment(
            CrewPhaseCode::PreMobilisation,
            CrewPhaseCode::OnVessel,
        ))->toBeFalse();
});

test('p1a can move to p1b or p2', function () {
    expect(CrewMovementTransitionMap::canTransitionWithinAssignment(
        CrewPhaseCode::JoinStandby,
        CrewPhaseCode::Training,
    ))->toBeTrue()
        ->and(CrewMovementTransitionMap::canTransitionWithinAssignment(
            CrewPhaseCode::JoinStandby,
            CrewPhaseCode::OnVessel,
        ))->toBeTrue();
});

test('p1b returns to p1a', function () {
    expect(CrewMovementTransitionMap::canTransitionWithinAssignment(
        CrewPhaseCode::Training,
        CrewPhaseCode::JoinStandby,
    ))->toBeTrue();
});

test('p2 can move to p3 or p4 home', function () {
    expect(CrewMovementTransitionMap::canTransitionWithinAssignment(
        CrewPhaseCode::OnVessel,
        CrewPhaseCode::DemobStandby,
    ))->toBeTrue()
        ->and(CrewMovementTransitionMap::canTransitionWithinAssignment(
            CrewPhaseCode::OnVessel,
            CrewPhaseCode::HomeRedeploy,
        ))->toBeTrue();
});

test('p3 can move to p4 home', function () {
    expect(CrewMovementTransitionMap::canTransitionWithinAssignment(
        CrewPhaseCode::DemobStandby,
        CrewPhaseCode::HomeRedeploy,
    ))->toBeTrue();
});

test('invalid backward transitions are rejected', function () {
    expect(CrewMovementTransitionMap::canTransitionWithinAssignment(
        CrewPhaseCode::OnVessel,
        CrewPhaseCode::PreMobilisation,
    ))->toBeFalse()
        ->and(CrewMovementTransitionMap::canTransitionWithinAssignment(
            CrewPhaseCode::Training,
            CrewPhaseCode::OnVessel,
        ))->toBeFalse();
});

test('p2 cannot transition directly to p1a in the same assignment', function () {
    expect(CrewMovementTransitionMap::canTransitionWithinAssignment(
        CrewPhaseCode::OnVessel,
        CrewPhaseCode::JoinStandby,
    ))->toBeFalse();
});

test('p3 demob does not return p0 in allowed next phases', function () {
    expect(CrewMovementTransitionMap::allowedNextPhases(CrewPhaseCode::DemobStandby))
        ->not->toContain(CrewPhaseCode::PreMobilisation);
});

test('p4 home does not return p0 in allowed next phases', function () {
    expect(CrewMovementTransitionMap::allowedNextPhases(CrewPhaseCode::HomeRedeploy))->toBe([]);
});

test('p3 and p4 home can start a linked assignment', function () {
    expect(CrewMovementTransitionMap::canStartLinkedAssignment(CrewPhaseCode::DemobStandby))->toBeTrue()
        ->and(CrewMovementTransitionMap::canStartLinkedAssignment(CrewPhaseCode::HomeRedeploy))->toBeTrue();
});

test('p2 on vessel cannot start a linked assignment', function () {
    expect(CrewMovementTransitionMap::canStartLinkedAssignment(CrewPhaseCode::OnVessel))->toBeFalse();
});

test('p1a allows training and join vessel only', function () {
    $next = CrewMovementTransitionMap::allowedNextPhases(CrewPhaseCode::JoinStandby);

    expect($next)->toHaveCount(2)
        ->and($next)->toContain(CrewPhaseCode::Training, CrewPhaseCode::OnVessel);
});
