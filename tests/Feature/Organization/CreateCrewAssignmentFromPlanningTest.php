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

    $assignment = app(CreateCrewAssignmentFromPlanning::class)->handle($planning);

    expect($assignment->status)->toBe(CrewAssignmentStatus::Draft)
        ->and($assignment->source)->toBe('crew_planning')
        ->and($assignment->vessel_id)->toBe($vessel->id)
        ->and($assignment->planned_join_at->toDateString())->toBe('2027-03-01')
        ->and($assignment->planned_signoff_at->toDateString())->toBe('2027-08-31')
        ->and($planning->fresh()->crew_assignment_id)->toBe($assignment->id)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation);
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
    $first = $service->handle($planning);
    $second = $service->handle($planning->fresh());

    expect($second->id)->toBe($first->id)
        ->and(CrewAssignment::query()->where('employee_id', $employee->id)->count())->toBe(1);
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

    expect(fn () => app(CreateCrewAssignmentFromPlanning::class)->handle($planning))
        ->toThrow(CrewMovementException::class);
});

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
        ->and($otherAssignment->company_id)->toBe($otherCompany->id);
});
