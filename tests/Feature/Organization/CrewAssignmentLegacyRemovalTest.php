<?php

use App\Models\EmployeeDeployment;
use App\Support\CrewDeployments\CrewDeploymentBoardQuery;
use App\Support\CrewDeployments\DeploymentStatus;
use App\Support\CrewMovements\LegacyDeploymentBackfillService;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

test('EmployeeDeployment class no longer exists', function () {
    expect(class_exists(EmployeeDeployment::class, false))->toBeFalse();
});

test('employee_deployments table no longer exists', function () {
    expect(Schema::hasTable('employee_deployments'))->toBeFalse();
});

test('crew deployments routes no longer exist', function () {
    expect(fn () => route('organization.crew-deployments.index'))
        ->toThrow(RouteNotFoundException::class);
});

test('legacy deployment support classes no longer exist', function () {
    expect(class_exists(CrewDeploymentBoardQuery::class, false))->toBeFalse()
        ->and(class_exists(DeploymentStatus::class, false))->toBeFalse()
        ->and(class_exists(LegacyDeploymentBackfillService::class, false))->toBeFalse();
});

test('crew assignment tables exist', function () {
    expect(Schema::hasTable('crew_assignments'))->toBeTrue()
        ->and(Schema::hasTable('crew_assignment_phases'))->toBeTrue()
        ->and(Schema::hasTable('crew_assignment_sequences'))->toBeTrue();
});

test('crew assignment model is functional', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Legacy Test Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    expect($assignment->employee_id)->toBe($employee->id)
        ->and($assignment->currentPhase)->not->toBeNull();
});
