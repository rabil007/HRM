<?php

use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseStatus;
use App\Enums\CrewPlannedSignoffSource;
use App\Enums\CrewTourOfDutySource;
use App\Models\CrewPlanningAssignment;
use App\Models\CrewRankPolicy;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Support\CrewMovements\CrewMovementService;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->fixtures = makeCrewAssignmentFixtures();
    $this->user = $this->fixtures['user'];
    $this->company = $this->fixtures['company'];
    $this->employee = $this->fixtures['employee'];
    $this->rank = $this->fixtures['rank'];
    $this->rank->update([
        'name' => 'Chief Officer '.uniqid(),
        'max_tour_of_duty_days' => 90,
    ]);
    $this->vessel = makeCrewMovementVessel('E2E Tour Vessel');
    $this->service = app(CrewMovementService::class);

    CrewRankPolicy::query()->create([
        'company_id' => $this->company->id,
        'rank_id' => $this->rank->id,
        'tour_of_duty_days' => 75,
        'is_active' => true,
    ]);
});

function advanceToReadyForE2ETour(CrewMovementService $service, int $companyId, int $assignmentId, int $userId): void
{
    $service->perform($companyId, $assignmentId, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-08-01 08:00:00',
    ], $userId);
    $service->perform($companyId, $assignmentId, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-08-05 10:00:00',
        'next_phase' => 'p2a',
    ], $userId);
    $service->perform($companyId, $assignmentId, CrewMovementAction::MarkReady, [
        'occurred_at' => '2026-08-08 09:00:00',
    ], $userId);
}

it('uses company policy tour suggestion on join vessel', function () {
    $assignment = $this->service->createDraft($this->company->id, $this->employee->id, [
        'rank_id' => $this->rank->id,
        'vessel_id' => $this->vessel->id,
        'planned_join_at' => '2026-08-10 00:00:00',
        'planned_signoff_at' => '2026-11-08 00:00:00',
    ], $this->user->id);

    advanceToReadyForE2ETour($this->service, $this->company->id, $assignment->id, $this->user->id);

    $this->service->perform($this->company->id, $assignment->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-08-12 10:00:00',
        'vessel_id' => $this->vessel->id,
        'rank_id' => $this->rank->id,
        'planned_signoff_choice' => 'tour_of_duty',
    ], $this->user->id);

    $assignment->refresh()->load('currentPhase', 'planningAssignment');

    expect($assignment->tour_of_duty_days)->toBe(75)
        ->and($assignment->tour_of_duty_source)->toBe(CrewTourOfDutySource::CompanyRankPolicy)
        ->and($assignment->planned_signoff_source)->toBe(CrewPlannedSignoffSource::TourOfDuty)
        ->and($assignment->planned_signoff_at?->timezone($this->company->timezone)->toDateString())->toBe('2026-10-26')
        ->and($assignment->currentPhase?->planned_end_at?->timezone($this->company->timezone)->toDateString())->toBe('2026-10-26')
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Active)
        ->and($assignment->currentPhase?->actual_end_at)->toBeNull()
        ->and(EmployeeSeaService::query()->where('employee_id', $this->employee->id)->exists())->toBeFalse()
        ->and($assignment->planningAssignment?->planned_leave_date?->toDateString())->toBe('2026-10-26');
});

it('keeps existing planning leave when existing_plan is chosen', function () {
    $assignment = $this->service->createDraft($this->company->id, $this->employee->id, [
        'rank_id' => $this->rank->id,
        'vessel_id' => $this->vessel->id,
        'planned_join_at' => '2026-08-10 00:00:00',
        'planned_signoff_at' => '2026-11-08 00:00:00',
    ], $this->user->id);

    advanceToReadyForE2ETour($this->service, $this->company->id, $assignment->id, $this->user->id);

    $this->service->perform($this->company->id, $assignment->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-08-12 10:00:00',
        'vessel_id' => $this->vessel->id,
        'rank_id' => $this->rank->id,
        'planned_signoff_choice' => 'existing_plan',
    ], $this->user->id);

    $assignment->refresh();

    expect($assignment->planned_signoff_at?->timezone($this->company->timezone)->toDateString())->toBe('2026-11-08')
        ->and($assignment->planned_signoff_source)->toBe(CrewPlannedSignoffSource::ExistingPlan)
        ->and($assignment->tour_of_duty_days)->toBe(75)
        ->and($assignment->tour_of_duty_source)->toBe(CrewTourOfDutySource::CompanyRankPolicy);
});

it('stores manual override with required reason', function () {
    $assignment = $this->service->createDraft($this->company->id, $this->employee->id, [
        'rank_id' => $this->rank->id,
        'vessel_id' => $this->vessel->id,
        'planned_join_at' => '2026-08-10 00:00:00',
        'planned_signoff_at' => '2026-11-08 00:00:00',
    ], $this->user->id);

    advanceToReadyForE2ETour($this->service, $this->company->id, $assignment->id, $this->user->id);

    expect(fn () => $this->service->perform($this->company->id, $assignment->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-08-12 10:00:00',
        'vessel_id' => $this->vessel->id,
        'rank_id' => $this->rank->id,
        'planned_signoff_choice' => 'manual_override',
        'planned_signoff_at' => '2026-09-30',
    ], $this->user->id))->toThrow(ValidationException::class);

    $this->service->perform($this->company->id, $assignment->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-08-12 10:00:00',
        'vessel_id' => $this->vessel->id,
        'rank_id' => $this->rank->id,
        'planned_signoff_choice' => 'manual_override',
        'planned_signoff_at' => '2026-09-30',
        'planned_signoff_override_reason' => 'Client requested earlier relief',
    ], $this->user->id);

    $assignment->refresh();

    expect($assignment->planned_signoff_source)->toBe(CrewPlannedSignoffSource::ManualOverride)
        ->and($assignment->planned_signoff_override_reason)->toBe('Client requested earlier relief')
        ->and($assignment->planned_signoff_at?->timezone($this->company->timezone)->toDateString())->toBe('2026-09-30')
        ->and($assignment->tour_of_duty_days)->toBe(75);
});

it('keeps snapshotted tour after later rank and company policy changes', function () {
    $assignment = $this->service->createDraft($this->company->id, $this->employee->id, [
        'rank_id' => $this->rank->id,
        'vessel_id' => $this->vessel->id,
    ], $this->user->id);

    advanceToReadyForE2ETour($this->service, $this->company->id, $assignment->id, $this->user->id);

    $this->service->perform($this->company->id, $assignment->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-08-12 10:00:00',
        'vessel_id' => $this->vessel->id,
        'rank_id' => $this->rank->id,
        'planned_signoff_choice' => 'tour_of_duty',
    ], $this->user->id);

    $this->rank->update(['max_tour_of_duty_days' => 120]);
    CrewRankPolicy::query()
        ->where('company_id', $this->company->id)
        ->where('rank_id', $this->rank->id)
        ->update(['tour_of_duty_days' => 150]);

    $assignment->refresh();

    expect($assignment->tour_of_duty_days)->toBe(75)
        ->and($assignment->planned_signoff_at?->timezone($this->company->timezone)->toDateString())->toBe('2026-10-26');

    $otherEmployee = Employee::factory()->forCompany($this->company)->create([
        'rank_id' => $this->rank->id,
        'status' => 'active',
    ]);
    $otherVessel = makeCrewMovementVessel('Future E2E Vessel');
    $future = $this->service->createDraft($this->company->id, $otherEmployee->id, [
        'rank_id' => $this->rank->id,
        'vessel_id' => $otherVessel->id,
    ], $this->user->id);

    advanceToReadyForE2ETour($this->service, $this->company->id, $future->id, $this->user->id);

    $this->service->perform($this->company->id, $future->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-09-01 10:00:00',
        'vessel_id' => $otherVessel->id,
        'rank_id' => $this->rank->id,
        'planned_signoff_choice' => 'tour_of_duty',
    ], $this->user->id);

    expect($future->fresh()->tour_of_duty_days)->toBe(150)
        ->and(CrewPlanningAssignment::query()->where('crew_assignment_id', $assignment->id)->exists())->toBeTrue()
        ->and(EmployeeSeaService::query()->count())->toBe(0);
});
