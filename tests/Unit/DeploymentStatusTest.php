<?php

use App\Models\EmployeeDeployment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);
use App\Support\CrewDeployments\DeploymentStatus;
use Carbon\CarbonImmutable;

test('deployment status resolves on vessel for open tour', function () {
    $deployment = new EmployeeDeployment([
        'vessel_name' => 'L Etoile',
        'joined_date' => CarbonImmutable::today()->subDays(10),
        'disembarked_date' => CarbonImmutable::today()->addDays(20),
    ]);

    $status = DeploymentStatus::resolve($deployment, CarbonImmutable::today());

    expect($status['status'])->toBe(DeploymentStatus::ON_VESSEL)
        ->and($status['label'])->toBe('On L Etoile');
});

test('deployment status resolves standby when between standby dates', function () {
    $deployment = new EmployeeDeployment([
        'standby_from' => CarbonImmutable::today()->subDay(),
        'standby_to' => CarbonImmutable::today()->addDays(3),
    ]);

    $status = DeploymentStatus::resolve($deployment, CarbonImmutable::today());

    expect($status['status'])->toBe(DeploymentStatus::STANDBY);
});

test('deployment status resolves travel after disembarkation', function () {
    $deployment = new EmployeeDeployment([
        'disembarked_date' => CarbonImmutable::today()->subDays(2),
        'travelled_date' => CarbonImmutable::today()->subDay(),
    ]);

    $status = DeploymentStatus::resolve($deployment, CarbonImmutable::today());

    expect($status['status'])->toBe(DeploymentStatus::TRAVEL);
});

test('deployment status calculates total days between join and disembark', function () {
    $deployment = new EmployeeDeployment([
        'joined_date' => '2024-01-01',
        'disembarked_date' => '2024-01-31',
    ]);

    expect(DeploymentStatus::totalDays($deployment))->toBe(31);
});
