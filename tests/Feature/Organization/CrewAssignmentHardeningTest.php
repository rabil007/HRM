<?php

use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewPlanningAssignment;
use Illuminate\Database\QueryException;

test('same crew assignment cannot link to two planning assignments', function () {
    ['employee' => $employee, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Planning Link');

    $assignment = CrewAssignment::factory()->forEmployee($employee)->create();

    CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'crew_assignment_id' => $assignment->id,
        'planned_join_date' => '2026-03-01',
        'planned_leave_date' => '2026-06-01',
    ]);

    expect(fn () => CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'crew_assignment_id' => $assignment->id,
        'planned_join_date' => '2026-04-01',
        'planned_leave_date' => '2026-07-01',
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

test('multiple assignments without planning links remain allowed', function () {
    ['employee' => $employee] = makeCrewAssignmentFixtures();

    CrewAssignment::factory()->forEmployee($employee)->create();
    CrewAssignment::factory()->forEmployee($employee)->create();

    expect(CrewAssignment::query()->where('employee_id', $employee->id)->count())->toBe(2);
});
