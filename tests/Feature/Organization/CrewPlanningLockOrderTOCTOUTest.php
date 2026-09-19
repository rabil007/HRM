<?php

use App\Enums\CrewAssignmentStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Support\CrewPlanning\CreateCrewAssignmentFromPlanning;
use App\Support\CrewPlanning\SaveCrewPlanningAssignment;
use App\Support\CrewPlanning\StartCrewAssignmentFromPlanning;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('CreateCrewAssignmentFromPlanning detects concurrent change in crew_assignment_id and retries safely', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Planning Lock Order Vessel', $company);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
        'notes' => 'Lock order test',
    ]);

    $otherEmployee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);
    $concurrentAssignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-2026-CONCURRENT-1',
        'employee_id' => $otherEmployee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'source' => 'crew_planning',
    ]);

    $injected = false;
    DB::listen(function ($query) use (&$injected, $planning, $concurrentAssignment) {
        if (! $injected && str_contains($query->sql, 'select "crew_assignment_id" from "crew_planning_assignments"')) {
            $injected = true;
            CrewPlanningAssignment::query()
                ->whereKey($planning->id)
                ->update(['crew_assignment_id' => $concurrentAssignment->id]);
        }
    });

    $service = app(CreateCrewAssignmentFromPlanning::class);
    $result = $service->handle($planning);

    // Attempt 1 detected mismatch and rolled back.
    // Attempt 2 safely retried and handled assignment creation cleanly.
    expect($injected)->toBeTrue()
        ->and($result->status)->toBe(CrewAssignmentStatus::Draft)
        ->and($planning->fresh()->crew_assignment_id)->toBe($result->id);
});

test('CreateCrewAssignmentFromPlanning throws concurrency exception when conflict persists across all retries', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Planning Failure Vessel', $company);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
    ]);

    $otherEmployee1 = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);
    $assignment1 = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-2026-FAIL-1',
        'employee_id' => $otherEmployee1->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'source' => 'crew_planning',
    ]);

    $otherEmployee2 = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);
    $assignment2 = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-2026-FAIL-2',
        'employee_id' => $otherEmployee2->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'source' => 'crew_planning',
    ]);

    $toggle = 0;
    DB::listen(function ($query) use (&$toggle, $planning, $assignment1, $assignment2) {
        if (str_contains($query->sql, 'select "crew_assignment_id" from "crew_planning_assignments"')) {
            $toggle++;
            CrewPlanningAssignment::query()
                ->whereKey($planning->id)
                ->update(['crew_assignment_id' => ($toggle % 2 === 1) ? $assignment1->id : $assignment2->id]);
        }
    });

    $service = app(CreateCrewAssignmentFromPlanning::class);

    try {
        $service->handle($planning);
        $this->fail('Expected CrewMovementException was not thrown');
    } catch (CrewMovementException $e) {
        expect($e->errorCode)->toBe('planning_concurrency_conflict');
    }
});

test('StartCrewAssignmentFromPlanning detects concurrent change in crew_assignment_id and retries safely', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Start Lock Order Vessel', $company);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
        'notes' => 'Start lock order test',
    ]);

    $otherEmployee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id]);
    $concurrentAssignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-2026-START-CONCURRENT-1',
        'employee_id' => $otherEmployee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Draft,
        'source' => 'crew_planning',
    ]);

    $injected = false;
    DB::listen(function ($query) use (&$injected, $planning, $concurrentAssignment) {
        if (! $injected && str_contains($query->sql, 'select "crew_assignment_id" from "crew_planning_assignments"')) {
            $injected = true;
            CrewPlanningAssignment::query()
                ->whereKey($planning->id)
                ->update(['crew_assignment_id' => $concurrentAssignment->id]);
        }
    });

    $service = app(StartCrewAssignmentFromPlanning::class);
    $result = $service->handle($planning, []);

    expect($injected)->toBeTrue()
        ->and($result['created_new'])->toBeTrue()
        ->and($result['assignment']->status)->toBe(CrewAssignmentStatus::Active);
});

test('SaveCrewPlanningAssignment update detects concurrent change in relieves_crew_assignment_id and retries safely', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Relief Lock Order Vessel', $company);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
        'relieves_crew_assignment_id' => null,
    ]);

    $injected = false;
    DB::listen(function ($query) use (&$injected, $planning) {
        if (! $injected && str_contains($query->sql, 'select "relieves_crew_assignment_id" from "crew_planning_assignments"')) {
            $injected = true;
            CrewPlanningAssignment::query()
                ->whereKey($planning->id)
                ->update(['notes' => 'concurrently updated']);
        }
    });

    $service = app(SaveCrewPlanningAssignment::class);
    $updated = $service->update($planning, $company->id, ['notes' => 'Updated successfully']);

    expect($updated->notes)->toBe('Updated successfully');
});

test('SaveCrewPlanningAssignment update fails with ValidationException when relief linkage changes concurrently beyond retries', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Relief Conflict Vessel', $company);

    $reliefAssignment1 = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);
    $otherEmployee = Employee::factory()->create(['company_id' => $company->id, 'rank_id' => $rank->id, 'department_id' => $employee->department_id]);
    $reliefAssignment2 = makeActiveOnVesselAssignment($company, $otherEmployee, $rank, $vessel);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
        'relieves_crew_assignment_id' => $reliefAssignment1->id,
    ]);

    $toggle = 0;
    DB::listen(function ($query) use (&$toggle, $planning, $reliefAssignment1, $reliefAssignment2) {
        if (str_contains($query->sql, 'select "relieves_crew_assignment_id" from "crew_planning_assignments"')) {
            $toggle++;
            CrewPlanningAssignment::query()
                ->whereKey($planning->id)
                ->update(['relieves_crew_assignment_id' => ($toggle % 2 === 1) ? $reliefAssignment2->id : $reliefAssignment1->id]);
        }
    });

    $service = app(SaveCrewPlanningAssignment::class);

    expect(fn () => $service->update($planning, $company->id, ['notes' => 'Will fail']))
        ->toThrow(ValidationException::class);
});
