<?php

test('EmployeeDeployment class no longer exists', function () {
    expect(class_exists('App\\Models\\EmployeeDeployment'))->toBeFalse();
});

test('employee_deployments table no longer exists', function () {
    $hasTable = Schema::hasTable('employee_deployments');

    expect($hasTable)->toBeFalse();
});

test('crew deployments board route no longer exists', function () {
    ['user' => $user] = makeCrewAssignmentFixtures();

    $this->actingAs($user)
        ->get('/organization/crew-deployments')
        ->assertNotFound();
});

test('crew deployments show route no longer exists', function () {
    ['user' => $user] = makeCrewAssignmentFixtures();

    $this->actingAs($user)
        ->get('/organization/crew-deployments/show/999')
        ->assertNotFound();
});

test('crew deployment board query class no longer exists', function () {
    expect(class_exists('App\\Support\\CrewDeployments\\CrewDeploymentBoardQuery'))->toBeFalse();
});

test('deployment status enum no longer exists', function () {
    expect(class_exists('App\\Support\\CrewDeployments\\DeploymentStatus'))->toBeFalse();
});

test('legacy deployment backfill service no longer exists', function () {
    expect(class_exists('App\\Support\\CrewMovements\\LegacyDeploymentBackfillService'))->toBeFalse();
});

test('crew assignments table exists', function () {
    expect(Schema::hasTable('crew_assignments'))->toBeTrue();
});

test('crew assignment phases table exists', function () {
    expect(Schema::hasTable('crew_assignment_phases'))->toBeTrue();
});

test('crew assignment sequences table exists', function () {
    expect(Schema::hasTable('crew_assignment_sequences'))->toBeTrue();
});

test('crew assignment model exists and is functional', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Legacy Test Vessel');

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    expect($assignment)->not->toBeNull()
        ->and($assignment->employee_id)->toBe($employee->id)
        ->and($assignment->vessel_id)->toBe($vessel->id)
        ->and($assignment->currentPhase)->not->toBeNull();
});
