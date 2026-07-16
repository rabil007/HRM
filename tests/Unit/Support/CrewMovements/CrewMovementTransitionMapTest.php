<?php

use App\Enums\CrewPhaseCode;
use App\Support\CrewMovements\CrewMovementTransitionMap;

test('p0 can move to p1', function () {
    expect(CrewMovementTransitionMap::canTransition(
        CrewPhaseCode::PreMobilisation,
        CrewPhaseCode::TravelIn,
    ))->toBeTrue();
});

test('p1 can move to p2a', function () {
    expect(CrewMovementTransitionMap::canTransition(
        CrewPhaseCode::TravelIn,
        CrewPhaseCode::JoinStandby,
    ))->toBeTrue();
});

test('p1 can move directly to p3', function () {
    expect(CrewMovementTransitionMap::canTransition(
        CrewPhaseCode::TravelIn,
        CrewPhaseCode::ReadyToJoin,
    ))->toBeTrue();
});

test('p2a can move to p2b', function () {
    expect(CrewMovementTransitionMap::canTransition(
        CrewPhaseCode::JoinStandby,
        CrewPhaseCode::Training,
    ))->toBeTrue();
});

test('p2b can return to p2a', function () {
    expect(CrewMovementTransitionMap::canTransition(
        CrewPhaseCode::Training,
        CrewPhaseCode::JoinStandby,
    ))->toBeTrue();
});

test('p2b can move to p3', function () {
    expect(CrewMovementTransitionMap::canTransition(
        CrewPhaseCode::Training,
        CrewPhaseCode::ReadyToJoin,
    ))->toBeTrue();
});

test('p3 can move to p4', function () {
    expect(CrewMovementTransitionMap::canTransition(
        CrewPhaseCode::ReadyToJoin,
        CrewPhaseCode::OnVessel,
    ))->toBeTrue();
});

test('p4 can move to p5', function () {
    expect(CrewMovementTransitionMap::canTransition(
        CrewPhaseCode::OnVessel,
        CrewPhaseCode::DemobStandby,
    ))->toBeTrue();
});

test('p4 can move directly to p6', function () {
    expect(CrewMovementTransitionMap::canTransition(
        CrewPhaseCode::OnVessel,
        CrewPhaseCode::HomeRedeploy,
    ))->toBeTrue();
});

test('p5 can move to p6', function () {
    expect(CrewMovementTransitionMap::canTransition(
        CrewPhaseCode::DemobStandby,
        CrewPhaseCode::HomeRedeploy,
    ))->toBeTrue();
});

test('invalid backward transitions are rejected', function () {
    expect(CrewMovementTransitionMap::canTransition(
        CrewPhaseCode::OnVessel,
        CrewPhaseCode::PreMobilisation,
    ))->toBeFalse()
        ->and(CrewMovementTransitionMap::canTransition(
            CrewPhaseCode::ReadyToJoin,
            CrewPhaseCode::TravelIn,
        ))->toBeFalse()
        ->and(CrewMovementTransitionMap::canTransition(
            CrewPhaseCode::TravelIn,
            CrewPhaseCode::PreMobilisation,
        ))->toBeFalse();
});

test('p4 cannot transition directly to p2a in the same assignment', function () {
    expect(CrewMovementTransitionMap::canTransition(
        CrewPhaseCode::OnVessel,
        CrewPhaseCode::JoinStandby,
    ))->toBeFalse();
});

test('allowed next phases returns typed enum instances', function () {
    $next = CrewMovementTransitionMap::allowedNextPhases(CrewPhaseCode::JoinStandby);

    expect($next)->toHaveCount(3)
        ->and($next[0])->toBeInstanceOf(CrewPhaseCode::class)
        ->and($next)->toContain(CrewPhaseCode::Training)
        ->and($next)->toContain(CrewPhaseCode::ReadyToJoin)
        ->and($next)->toContain(CrewPhaseCode::OnVessel);
});
