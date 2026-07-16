<?php

use App\Enums\CrewPhaseCode;
use App\Support\CrewMovements\CrewMovementTransitionMap;

test('p0 can move to p1', function () {
    expect(CrewMovementTransitionMap::canTransitionWithinAssignment(
        CrewPhaseCode::PreMobilisation,
        CrewPhaseCode::TravelIn,
    ))->toBeTrue();
});

test('p1 can move to p2a', function () {
    expect(CrewMovementTransitionMap::canTransitionWithinAssignment(
        CrewPhaseCode::TravelIn,
        CrewPhaseCode::JoinStandby,
    ))->toBeTrue();
});

test('p1 can move directly to p3', function () {
    expect(CrewMovementTransitionMap::canTransitionWithinAssignment(
        CrewPhaseCode::TravelIn,
        CrewPhaseCode::ReadyToJoin,
    ))->toBeTrue();
});

test('p2a can move to p2b', function () {
    expect(CrewMovementTransitionMap::canTransitionWithinAssignment(
        CrewPhaseCode::JoinStandby,
        CrewPhaseCode::Training,
    ))->toBeTrue();
});

test('p2b can return to p2a', function () {
    expect(CrewMovementTransitionMap::canTransitionWithinAssignment(
        CrewPhaseCode::Training,
        CrewPhaseCode::JoinStandby,
    ))->toBeTrue();
});

test('p2b can move to p3', function () {
    expect(CrewMovementTransitionMap::canTransitionWithinAssignment(
        CrewPhaseCode::Training,
        CrewPhaseCode::ReadyToJoin,
    ))->toBeTrue();
});

test('p3 can move to p4', function () {
    expect(CrewMovementTransitionMap::canTransitionWithinAssignment(
        CrewPhaseCode::ReadyToJoin,
        CrewPhaseCode::OnVessel,
    ))->toBeTrue();
});

test('p4 can move to p5', function () {
    expect(CrewMovementTransitionMap::canTransitionWithinAssignment(
        CrewPhaseCode::OnVessel,
        CrewPhaseCode::DemobStandby,
    ))->toBeTrue();
});

test('p4 can move directly to p6', function () {
    expect(CrewMovementTransitionMap::canTransitionWithinAssignment(
        CrewPhaseCode::OnVessel,
        CrewPhaseCode::HomeRedeploy,
    ))->toBeTrue();
});

test('p5 can move to p6', function () {
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
            CrewPhaseCode::ReadyToJoin,
            CrewPhaseCode::TravelIn,
        ))->toBeFalse();
});

test('p4 cannot transition directly to p2a in the same assignment', function () {
    expect(CrewMovementTransitionMap::canTransitionWithinAssignment(
        CrewPhaseCode::OnVessel,
        CrewPhaseCode::JoinStandby,
    ))->toBeFalse();
});

test('p5 does not return p0 in allowed next phases', function () {
    expect(CrewMovementTransitionMap::allowedNextPhases(CrewPhaseCode::DemobStandby))
        ->not->toContain(CrewPhaseCode::PreMobilisation)
        ->and(CrewMovementTransitionMap::canTransitionWithinAssignment(
            CrewPhaseCode::DemobStandby,
            CrewPhaseCode::PreMobilisation,
        ))->toBeFalse();
});

test('p6 does not return p0 in allowed next phases', function () {
    expect(CrewMovementTransitionMap::allowedNextPhases(CrewPhaseCode::HomeRedeploy))
        ->toBe([])
        ->and(CrewMovementTransitionMap::canTransitionWithinAssignment(
            CrewPhaseCode::HomeRedeploy,
            CrewPhaseCode::PreMobilisation,
        ))->toBeFalse();
});

test('p5 and p6 can start a linked assignment', function () {
    expect(CrewMovementTransitionMap::canStartLinkedAssignment(CrewPhaseCode::DemobStandby))->toBeTrue()
        ->and(CrewMovementTransitionMap::canStartLinkedAssignment(CrewPhaseCode::HomeRedeploy))->toBeTrue();
});

test('p4 cannot start a linked assignment', function () {
    expect(CrewMovementTransitionMap::canStartLinkedAssignment(CrewPhaseCode::OnVessel))->toBeFalse();
});

test('allowed next phases returns typed enum instances', function () {
    $next = CrewMovementTransitionMap::allowedNextPhases(CrewPhaseCode::JoinStandby);

    expect($next)->toHaveCount(3)
        ->and($next[0])->toBeInstanceOf(CrewPhaseCode::class)
        ->and($next)->toContain(CrewPhaseCode::Training);
});

test('deprecated canTransition mirrors within-assignment rules', function () {
    expect(CrewMovementTransitionMap::canTransition(
        CrewPhaseCode::DemobStandby,
        CrewPhaseCode::PreMobilisation,
    ))->toBeFalse()
        ->and(CrewMovementTransitionMap::canTransition(
            CrewPhaseCode::DemobStandby,
            CrewPhaseCode::HomeRedeploy,
        ))->toBeTrue();
});
