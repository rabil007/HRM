<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Employee;
use App\Support\CrewMovements\CrewAssignmentInvariantGuard;

test('assignment and employee company mismatch is rejected', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();
    ['company' => $otherCompany] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create([
        'company_id' => $otherCompany->id,
    ]);

    expect(fn () => app(CrewAssignmentInvariantGuard::class)->assertValid($assignment->fresh()))
        ->toThrow(CrewMovementException::class, 'Assignment company does not match employee company.');
});

test('phase and assignment company mismatch is rejected', function () {
    ['employee' => $employee, 'company' => $company] = makeCrewAssignmentFixtures();
    ['company' => $otherCompany] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create();
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'company_id' => $otherCompany->id,
        'sequence' => 1,
    ]);

    expect(fn () => app(CrewAssignmentInvariantGuard::class)->assertValid($assignment->fresh()->load('phases')))
        ->toThrow(CrewMovementException::class, 'Phase company does not match assignment company.');
});

test('current phase from another assignment is rejected', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignmentA = CrewAssignment::factory()->forEmployee($employee)->create();
    $assignmentB = CrewAssignment::factory()->forEmployee($employee)->create();
    $phaseB = CrewAssignmentPhase::factory()->forAssignment($assignmentB)->active()->create([
        'sequence' => 1,
    ]);

    $assignmentA->update(['current_phase_id' => $phaseB->id]);

    expect(fn () => app(CrewAssignmentInvariantGuard::class)->assertValid($assignmentA->fresh()))
        ->toThrow(CrewMovementException::class, 'Current phase does not belong to this assignment.');
});

test('previous assignment for another employee is rejected', function () {
    ['company' => $company, 'employee' => $employeeA] = makeCrewAssignmentFixtures();

    $employeeB = Employee::factory()
        ->forCompany($company)
        ->create([
            'rank_id' => $employeeA->rank_id,
            'status' => 'active',
        ]);

    $previous = CrewAssignment::factory()->forEmployee($employeeB)->completed()->create();
    $assignment = CrewAssignment::factory()->forEmployee($employeeA)->create([
        'previous_assignment_id' => $previous->id,
    ]);

    expect(fn () => app(CrewAssignmentInvariantGuard::class)->assertValid($assignment->fresh()))
        ->toThrow(CrewMovementException::class, 'Previous assignment belongs to a different employee.');
});

test('more than one active phase is rejected', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()->forEmployee($employee)->active()->create();
    CrewAssignmentPhase::factory()->forAssignment($assignment)->active()->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
    ]);
    CrewAssignmentPhase::factory()->forAssignment($assignment)->active()->create([
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 2,
    ]);

    expect(fn () => app(CrewAssignmentInvariantGuard::class)->assertValid($assignment->fresh()->load('phases')))
        ->toThrow(CrewMovementException::class, 'An assignment cannot have more than one active phase.');
});

test('completed assignment without closed_at is rejected', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create([
        'status' => CrewAssignmentStatus::Completed,
        'started_at' => now()->subMonth(),
        'closed_at' => null,
    ]);

    expect(fn () => app(CrewAssignmentInvariantGuard::class)->assertValid($assignment->fresh()))
        ->toThrow(CrewMovementException::class, 'Completed assignment must have closed_at.');
});

test('active assignment without started_at is rejected', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create([
        'status' => CrewAssignmentStatus::Active,
        'started_at' => null,
    ]);

    expect(fn () => app(CrewAssignmentInvariantGuard::class)->assertValid($assignment->fresh()))
        ->toThrow(CrewMovementException::class, 'Active assignment must have started_at.');
});

test('draft assignment may have planned current phase', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()->forEmployee($employee)->draft()->create();
    $phase = CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::PreMobilisation,
        'status' => CrewPhaseStatus::Planned,
        'sequence' => 1,
    ]);
    $assignment->update(['current_phase_id' => $phase->id]);

    app(CrewAssignmentInvariantGuard::class)->assertValid($assignment->fresh());

    expect($assignment->fresh()->currentPhase?->status)->toBe(CrewPhaseStatus::Planned);
});
