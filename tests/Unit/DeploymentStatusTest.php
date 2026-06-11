<?php

use App\Models\EmployeeDeployment;
use App\Support\CrewDeployments\DeploymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

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

test('deployment status resolves join standby when between join standby dates', function () {
    $deployment = new EmployeeDeployment([
        'join_standby_from' => CarbonImmutable::today()->subDay(),
        'join_standby_to' => CarbonImmutable::today()->addDays(3),
    ]);

    $status = DeploymentStatus::resolve($deployment, CarbonImmutable::today());

    expect($status['status'])->toBe(DeploymentStatus::JOIN_STANDBY)
        ->and($status['label'])->toBe('Join standby');
});

test('deployment status resolves leave standby after disembarkation', function () {
    $deployment = new EmployeeDeployment([
        'disembarked_date' => CarbonImmutable::today()->subDay(),
        'leave_standby_from' => CarbonImmutable::today()->subDay(),
        'leave_standby_to' => CarbonImmutable::today()->addDays(3),
    ]);

    $status = DeploymentStatus::resolve($deployment, CarbonImmutable::today());

    expect($status['status'])->toBe(DeploymentStatus::LEAVE_STANDBY)
        ->and($status['label'])->toBe('Leave standby');
});

test('deployment status resolves travel after disembarkation', function () {
    $deployment = new EmployeeDeployment([
        'disembarked_date' => CarbonImmutable::today()->subDays(2),
        'travelled_date' => CarbonImmutable::today()->subDay(),
    ]);

    $status = DeploymentStatus::resolve($deployment, CarbonImmutable::today());

    expect($status['status'])->toBe(DeploymentStatus::TRAVEL)
        ->and($status['label'])->toBe('Travelled');
});

test('deployment status calculates vessel days between join and disembark', function () {
    $deployment = new EmployeeDeployment([
        'joined_date' => '2024-01-01',
        'disembarked_date' => '2024-01-31',
    ]);

    expect(DeploymentStatus::vesselDays($deployment))->toBe(31);
});
