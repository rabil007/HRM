<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Support\CrewPlanning\CreateCrewAssignmentFromPlanning;

test('handle creates draft assignment from planning with all fields', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Planning Vessel');

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-03-01',
        'planned_leave_date' => '2027-08-31',
        'notes' => 'Test planning assignment',
    ]);

    $service = app(CreateCrewAssignmentFromPlanning::class);
    $assignment = $service->handle($planning);

    expect($assignment)->not->toBeNull()
        ->and($assignment->company_id)->toBe($company->id)
        ->and($assignment->employee_id)->toBe($employee->id)
        ->and($assignment->rank_id)->toBe($rank->id)
        ->and($assignment->vessel_id)->toBe($vessel->id)
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Draft)
        ->and($assignment->source)->toBe('crew_planning')
        ->and($assignment->remarks)->toBe('Test planning assignment')
        ->and($assignment->planned_join_at->toDateString())->toBe('2027-03-01')
        ->and($assignment->planned_signoff_at->toDateString())->toBe('2027-08-31');

    $planning->refresh();
    expect($planning->crew_assignment_id)->toBe($assignment->id);
});

test('handle creates draft with P0 phase', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Phase Vessel');

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-04-01',
        'planned_leave_date' => '2027-09-30',
    ]);

    $service = app(CreateCrewAssignmentFromPlanning::class);
    $assignment = $service->handle($planning);

    $phases = $assignment->phases;

    expect($phases)->toHaveCount(1)
        ->and($phases[0]->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and($assignment->current_phase_id)->toBe($phases[0]->id);
});

test('handle is idempotent and returns existing assignment', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Idempotent Vessel');

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-05-01',
        'planned_leave_date' => '2027-10-31',
    ]);

    $service = app(CreateCrewAssignmentFromPlanning::class);
    $firstAssignment = $service->handle($planning);

    expect($firstAssignment)->not->toBeNull();

    $secondAssignment = $service->handle($planning->fresh());

    expect($secondAssignment->id)->toBe($firstAssignment->id);

    $assignmentCount = CrewAssignment::query()
        ->where('company_id', $company->id)
        ->where('employee_id', $employee->id)
        ->count();

    expect($assignmentCount)->toBe(1);
});

test('handle throws exception when planning has no employee', function () {
    ['company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('No Employee Vessel');

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => null,
        'planned_join_date' => '2027-06-01',
        'planned_leave_date' => '2027-11-30',
    ]);

    $service = app(CreateCrewAssignmentFromPlanning::class);

    expect(fn () => $service->handle($planning))
        ->toThrow(CrewMovementException::class);
})->throws(CrewMovementException::class, 'Planning assignment has no employee.');

test('handle throws exception when planning has no vessel', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => null,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-07-01',
        'planned_leave_date' => '2027-12-31',
    ]);

    $service = app(CreateCrewAssignmentFromPlanning::class);

    expect(fn () => $service->handle($planning))
        ->toThrow(CrewMovementException::class);
})->throws(CrewMovementException::class, 'Planning assignment requires vessel and rank.');

test('handle throws exception when planning has no join date', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('No Date Vessel');

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => null,
        'planned_leave_date' => '2027-12-31',
    ]);

    $service = app(CreateCrewAssignmentFromPlanning::class);

    expect(fn () => $service->handle($planning))
        ->toThrow(CrewMovementException::class);
})->throws(CrewMovementException::class, 'Planning assignment requires a planned join date.');

test('handle allows planning without leave date', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('No Leave Date Vessel');

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-08-01',
        'planned_leave_date' => null,
    ]);

    $service = app(CreateCrewAssignmentFromPlanning::class);
    $assignment = $service->handle($planning);

    expect($assignment)->not->toBeNull()
        ->and($assignment->planned_signoff_at)->toBeNull();
});

test('handle blocks when employee has active assignment', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $firstVessel = makeCrewMovementVessel('First Vessel');
    $secondVessel = makeCrewMovementVessel('Second Vessel');

    makeActiveOnVesselAssignment($company, $employee, $rank, $firstVessel);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $secondVessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-09-01',
        'planned_leave_date' => '2027-12-31',
    ]);

    $service = app(CreateCrewAssignmentFromPlanning::class);

    expect(fn () => $service->handle($planning))
        ->toThrow(CrewMovementException::class);
})->throws(CrewMovementException::class);

test('handle is scoped to company', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    ['company' => $otherCompany, 'employee' => $otherEmployee] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Multi Company Vessel');

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2027-10-01',
        'planned_leave_date' => '2028-01-31',
    ]);

    $otherPlanning = CrewPlanningAssignment::query()->create([
        'company_id' => $otherCompany->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $otherEmployee->id,
        'planned_join_date' => '2027-10-01',
        'planned_leave_date' => '2028-01-31',
    ]);

    $service = app(CreateCrewAssignmentFromPlanning::class);
    $assignment = $service->handle($planning);
    $otherAssignment = $service->handle($otherPlanning);

    expect($assignment->company_id)->toBe($company->id)
        ->and($otherAssignment->company_id)->toBe($otherCompany->id)
        ->and($assignment->id)->not->toBe($otherAssignment->id);

    $companyCount = CrewAssignment::query()
        ->where('company_id', $company->id)
        ->count();

    expect($companyCount)->toBe(1);

    $otherCount = CrewAssignment::query()
        ->where('company_id', $otherCompany->id)
        ->count();

    expect($otherCount)->toBe(1);
});
