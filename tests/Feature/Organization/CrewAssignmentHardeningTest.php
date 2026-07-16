<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use App\Models\EmployeeDeployment;
use Illuminate\Database\QueryException;

test('same deployment cannot link to two assignments', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $deployment = EmployeeDeployment::factory()->forEmployee($employee)->create([
        'joined_date' => '2026-01-10',
        'disembarked_date' => null,
    ]);

    CrewAssignment::factory()->forEmployee($employee)->create([
        'employee_deployment_id' => $deployment->id,
    ]);

    expect(fn () => CrewAssignment::factory()->forEmployee($employee)->create([
        'employee_deployment_id' => $deployment->id,
    ]))->toThrow(QueryException::class);
});

test('same planning assignment cannot link to two assignments', function () {
    ['employee' => $employee, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Planning Link');

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-03-01',
        'planned_leave_date' => '2026-06-01',
    ]);

    CrewAssignment::factory()->forEmployee($employee)->create([
        'crew_planning_assignment_id' => $planning->id,
    ]);

    expect(fn () => CrewAssignment::factory()->forEmployee($employee)->create([
        'crew_planning_assignment_id' => $planning->id,
    ]))->toThrow(QueryException::class);
});

test('employee hard deletion is restricted when movement history exists', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    CrewAssignment::factory()->forEmployee($employee)->create();

    expect(fn () => $employee->forceDelete())->toThrow(QueryException::class);
    expect(CrewAssignment::query()->where('employee_id', $employee->id)->exists())->toBeTrue();
});

test('phase sequence uniqueness remains enforced', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create();
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create(['sequence' => 1]);

    expect(fn () => CrewAssignmentPhase::factory()->forAssignment($assignment)->create(['sequence' => 1]))
        ->toThrow(QueryException::class);
});

test('repeatable phase codes remain allowed', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create();

    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'status' => CrewPhaseStatus::Completed,
        'sequence' => 1,
    ]);
    CrewAssignmentPhase::factory()->forAssignment($assignment)->create([
        'phase_code' => CrewPhaseCode::JoinStandby,
        'status' => CrewPhaseStatus::Active,
        'sequence' => 2,
    ]);

    expect($assignment->phases()->where('phase_code', CrewPhaseCode::JoinStandby)->count())->toBe(2);
});

test('multiple null compatibility links remain allowed', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    CrewAssignment::factory()->forEmployee($employee)->create([
        'employee_deployment_id' => null,
        'crew_planning_assignment_id' => null,
    ]);
    CrewAssignment::factory()->forEmployee($employee)->create([
        'employee_deployment_id' => null,
        'crew_planning_assignment_id' => null,
    ]);

    expect(CrewAssignment::query()->where('employee_id', $employee->id)->count())->toBe(2);
});
