<?php

use App\Enums\CrewReliefRisk;
use App\Enums\CrewReliefStatus;
use App\Support\CrewMovements\CrewReliefReadinessResolver;

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
