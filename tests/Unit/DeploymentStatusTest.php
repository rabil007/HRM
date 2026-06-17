<?php

use App\Models\EmployeeDeployment;
use App\Models\Vessel;
use App\Support\CrewDeployments\DeploymentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function deploymentWithVessel(string $name, array $attributes = []): EmployeeDeployment
{
    $deployment = new EmployeeDeployment($attributes);
    $deployment->setRelation('vessel', new Vessel(['name' => $name]));

    return $deployment;
}

test('deployment status resolves on vessel for open tour', function () {
    $deployment = deploymentWithVessel('L Etoile', [
        'joined_date' => CarbonImmutable::today()->subDays(10),
        'disembarked_date' => CarbonImmutable::today()->addDays(20),
    ]);

    $status = DeploymentStatus::resolve($deployment, CarbonImmutable::today());

    expect($status['status'])->toBe(DeploymentStatus::ON_VESSEL)
        ->and($status['label'])->toBe('On L Etoile');
});

test('deployment status resolves join standby when only from date is set', function () {
    $today = CarbonImmutable::parse('2026-06-11');

    $deployment = new EmployeeDeployment([
        'arrived_date' => $today->subDays(10),
        'join_standby_from' => $today->subDays(9),
        'join_standby_to' => null,
    ]);

    $status = DeploymentStatus::resolve($deployment, $today);

    expect($status['status'])->toBe(DeploymentStatus::JOIN_STANDBY)
        ->and($status['label'])->toBe('Join standby');
});

test('deployment status resolves leave standby when only from date is set', function () {
    $today = CarbonImmutable::parse('2026-06-11');

    $deployment = new EmployeeDeployment([
        'disembarked_date' => $today->subDays(3),
        'leave_standby_from' => $today->subDays(2),
        'leave_standby_to' => null,
    ]);

    $status = DeploymentStatus::resolve($deployment, $today);

    expect($status['status'])->toBe(DeploymentStatus::LEAVE_STANDBY)
        ->and($status['label'])->toBe('Leave standby');
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

test('deployment status resolves arrived when arrived today or later without join date', function () {
    $today = CarbonImmutable::parse('2026-06-11');

    $awaitingToday = deploymentWithVessel('Vessel A', [
        'arrived_date' => $today,
    ]);

    $awaitingFuture = deploymentWithVessel('Vessel A', [
        'arrived_date' => $today->addDay(),
    ]);

    expect(DeploymentStatus::resolve($awaitingToday, $today)['status'])
        ->toBe(DeploymentStatus::ARRIVED)
        ->and(DeploymentStatus::resolve($awaitingToday, $today)['label'])
        ->toBe('Arrived')
        ->and(DeploymentStatus::resolve($awaitingFuture, $today)['status'])
        ->toBe(DeploymentStatus::ARRIVED);
});

test('deployment status resolves needs update when arrival passed without join date', function () {
    $today = CarbonImmutable::parse('2026-06-11');

    $deployment = deploymentWithVessel('Vessel A', [
        'arrived_date' => $today->subDay(),
    ]);

    $status = DeploymentStatus::resolve($deployment, $today);

    expect($status['status'])->toBe(DeploymentStatus::UNKNOWN)
        ->and($status['label'])->toBe('Needs update');
});

test('deployment status resolves disembarked only on disembark day', function () {
    $today = CarbonImmutable::parse('2026-06-11');

    $deployment = new EmployeeDeployment([
        'joined_date' => $today->subDays(2),
        'disembarked_date' => $today,
    ]);

    $status = DeploymentStatus::resolve($deployment, $today);

    expect($status['status'])->toBe(DeploymentStatus::DISEMBARKED)
        ->and($status['label'])->toBe('Disembarked');
});

test('deployment status resolves needs update when disembarked in past without travel or leave standby', function () {
    $today = CarbonImmutable::parse('2026-06-11');

    $deployment = new EmployeeDeployment([
        'joined_date' => $today->subDays(4),
        'disembarked_date' => $today->subDays(3),
    ]);

    $status = DeploymentStatus::resolve($deployment, $today);

    expect($status['status'])->toBe(DeploymentStatus::UNKNOWN)
        ->and($status['label'])->toBe('Needs update');
});

test('deployment status resolves needs update when leave standby ended without travel date', function () {
    $today = CarbonImmutable::parse('2026-06-11');

    $deployment = deploymentWithVessel('Vessel A', [
        'joined_date' => $today->subDays(4),
        'disembarked_date' => $today->subDays(3),
        'leave_standby_from' => $today->subDays(2),
        'leave_standby_to' => $today->subDay(),
        'travelled_date' => null,
    ]);

    $status = DeploymentStatus::resolve($deployment, $today);

    expect($status['status'])->toBe(DeploymentStatus::UNKNOWN)
        ->and($status['label'])->toBe('Needs update');
});

test('overdue date fields highlight the actionable date for needs update records', function () {
    $today = CarbonImmutable::parse('2026-06-11');

    $arrived = new EmployeeDeployment([
        'arrived_date' => $today->subDays(3),
    ]);

    $disembarked = new EmployeeDeployment([
        'joined_date' => $today->subDays(6),
        'disembarked_date' => $today->subDays(5),
    ]);

    $joinStandby = new EmployeeDeployment([
        'join_standby_from' => $today->subDays(8),
        'join_standby_to' => $today->subDays(2),
    ]);

    $leaveStandby = new EmployeeDeployment([
        'joined_date' => $today->subDays(6),
        'disembarked_date' => $today->subDays(5),
        'leave_standby_from' => $today->subDays(4),
        'leave_standby_to' => $today->subDays(2),
    ]);

    expect(DeploymentStatus::overdueDateFields($arrived, $today))->toBe(['arrived_date'])
        ->and(DeploymentStatus::overdueDateFields($disembarked, $today))->toBe(['disembarked_date'])
        ->and(DeploymentStatus::overdueDateFields($joinStandby, $today))->toBe(['join_standby_to'])
        ->and(DeploymentStatus::overdueDateFields($leaveStandby, $today))->toBe(['leave_standby_to']);
});

test('overdue date fields are empty when status is not needs update', function () {
    $deployment = new EmployeeDeployment([
        'joined_date' => CarbonImmutable::today()->subDays(2),
        'disembarked_date' => CarbonImmutable::today()->addDays(10),
    ]);

    expect(DeploymentStatus::overdueDateFields($deployment))->toBe([]);
});

test('due soon date fields highlight upcoming dates within two days only', function () {
    $today = CarbonImmutable::parse('2026-06-11');

    $arrivedYesterday = new EmployeeDeployment([
        'arrived_date' => $today->subDay(),
    ]);

    $disembarkedToday = new EmployeeDeployment([
        'joined_date' => $today->subDays(5),
        'disembarked_date' => $today,
    ]);

    $joinStandbyEndingSoon = new EmployeeDeployment([
        'join_standby_from' => $today->subDays(5),
        'join_standby_to' => $today->addDay(),
    ]);

    $vesselDisembarkSoon = new EmployeeDeployment([
        'joined_date' => $today->subDays(10),
        'disembarked_date' => $today->addDays(2),
    ]);

    expect(DeploymentStatus::overdueDateFields($arrivedYesterday, $today))->toBe(['arrived_date'])
        ->and(DeploymentStatus::dueSoonDateFields($arrivedYesterday, $today))->toBe([])
        ->and(DeploymentStatus::dueSoonDateFields($disembarkedToday, $today))->toBe([])
        ->and(DeploymentStatus::dueSoonDateFields($joinStandbyEndingSoon, $today))->toBe(['join_standby_to'])
        ->and(DeploymentStatus::dueSoonDateFields($vesselDisembarkSoon, $today))->toBe(['disembarked_date']);
});

test('needs update hint explains overdue arrival without join date', function () {
    $today = CarbonImmutable::parse('2026-06-11');

    $deployment = new EmployeeDeployment([
        'arrived_date' => $today->subDays(3),
    ]);

    expect(DeploymentStatus::needsUpdateHint($deployment, $today))
        ->toBe('Arrived 3d ago — add join date');
});

test('needs update hint explains overdue disembark without travel', function () {
    $today = CarbonImmutable::parse('2026-06-11');

    $deployment = new EmployeeDeployment([
        'joined_date' => $today->subDays(6),
        'disembarked_date' => $today->subDays(5),
    ]);

    expect(DeploymentStatus::needsUpdateHint($deployment, $today))
        ->toBe('Disembarked 5d ago — add travel or standby');
});

test('needs update hint explains leave standby ended without travel', function () {
    $today = CarbonImmutable::parse('2026-06-11');

    $deployment = new EmployeeDeployment([
        'joined_date' => $today->subDays(6),
        'disembarked_date' => $today->subDays(5),
        'leave_standby_from' => $today->subDays(4),
        'leave_standby_to' => $today->subDays(2),
    ]);

    expect(DeploymentStatus::needsUpdateHint($deployment, $today))
        ->toBe('Leave standby ended 2d ago — add travel date');
});

test('needs update hint is null when status is not needs update', function () {
    $deployment = new EmployeeDeployment([
        'joined_date' => CarbonImmutable::today()->subDays(2),
        'disembarked_date' => CarbonImmutable::today()->addDays(10),
    ]);

    expect(DeploymentStatus::needsUpdateHint($deployment))->toBeNull();
});

test('deployment status calculates vessel days between join and disembark', function () {
    $deployment = new EmployeeDeployment([
        'joined_date' => '2024-01-01',
        'disembarked_date' => '2024-01-31',
    ]);

    expect(DeploymentStatus::vesselDays($deployment))->toBe(31);
});

test('deployment status resolves in home when travelled on or before today', function () {
    $today = CarbonImmutable::parse('2026-06-12');

    $deployment = new EmployeeDeployment([
        'disembarked_date' => $today->subDays(5),
        'travelled_date' => $today->subDays(2),
    ]);

    expect(DeploymentStatus::isInHome($deployment, $today))->toBeTrue()
        ->and(DeploymentStatus::inHomeDays($deployment, $today))->toBe(3);
});

test('deployment status is not in home without travelled date', function () {
    $today = CarbonImmutable::parse('2026-06-12');

    $deployment = new EmployeeDeployment([
        'joined_date' => $today->subDays(6),
        'disembarked_date' => $today->subDays(3),
    ]);

    expect(DeploymentStatus::isInHome($deployment, $today))->toBeFalse()
        ->and(DeploymentStatus::inHomeDays($deployment, $today))->toBeNull();
});

test('deployment status is not in home when travelled date is in the future', function () {
    $today = CarbonImmutable::parse('2026-06-12');

    $deployment = new EmployeeDeployment([
        'disembarked_date' => $today->subDays(2),
        'travelled_date' => $today->addDay(),
    ]);

    expect(DeploymentStatus::isInHome($deployment, $today))->toBeFalse()
        ->and(DeploymentStatus::inHomeDays($deployment, $today))->toBeNull();
});

test('deployment status is not in home when latest record needs update', function () {
    $today = CarbonImmutable::parse('2026-06-12');

    $deployment = deploymentWithVessel('Vessel A', [
        'joined_date' => $today->subDays(6),
        'disembarked_date' => $today->subDays(3),
        'travelled_date' => null,
    ]);

    expect(DeploymentStatus::isInHome($deployment, $today))->toBeFalse();
});
