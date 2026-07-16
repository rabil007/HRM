<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exceptions\CrewMovementException;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Support\CrewMovements\CrewAssignmentInvariantGuard;
use App\Support\CrewMovements\CrewMovementService;
use Spatie\Activitylog\Models\Activity;

function crewMovementService(): CrewMovementService
{
    return app(CrewMovementService::class);
}

test('draft creation creates planned p0 and sets current phase', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();

    $assignment = crewMovementService()->createDraft(
        $company->id,
        $employee->id,
        [],
        $user->id,
    );

    expect($assignment->status)->toBe(CrewAssignmentStatus::Draft)
        ->and($assignment->assignment_no)->toStartWith('CA-')
        ->and($assignment->created_by)->toBe($user->id)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Planned)
        ->and($assignment->phases)->toHaveCount(1);
});

test('full happy path p0 through completed p6', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Happy Path');
    $service = crewMovementService();

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;

    $assignment = $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-01-01 08:00:00',
    ], $user->id);
    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::TravelIn);

    $assignment = $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-01-05 10:00:00',
        'next_phase' => 'p2a',
    ], $user->id);
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby);

    $assignment = $service->perform($company->id, $id, CrewMovementAction::MarkReady, [
        'occurred_at' => '2026-01-08 09:00:00',
    ], $user->id);
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::ReadyToJoin);

    $assignment = $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-01-10 12:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ], $user->id);
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($assignment->vessel_id)->toBe($vessel->id)
        ->and($assignment->currentPhase?->actual_end_at)->toBeNull();

    $assignment = $service->perform($company->id, $id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-04-01 08:00:00',
        'next_phase' => 'p5',
    ], $user->id);
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::DemobStandby);

    $assignment = $service->perform($company->id, $id, CrewMovementAction::TravelHome, [
        'occurred_at' => '2026-04-05 14:00:00',
    ], $user->id);
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::HomeRedeploy);

    $assignment = $service->perform($company->id, $id, CrewMovementAction::CloseAssignment, [
        'occurred_at' => '2026-04-06 09:00:00',
    ], $user->id);

    expect($assignment->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($assignment->closed_at)->not->toBeNull()
        ->and($assignment->current_phase_id)->not->toBeNull()
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::HomeRedeploy)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Completed)
        ->and($assignment->phases()->count())->toBe(7);
});

test('training loop creates a second p2a record', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Training Loop');
    $service = crewMovementService();

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-02-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-02-02 08:00:00',
        'next_phase' => 'p2a',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-02-03 08:00:00',
        'provider' => 'Falck',
        'course' => 'BOSIET',
    ], $user->id);
    $assignment = $service->perform($company->id, $id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-02-10 08:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($assignment->phases()->where('phase_code', CrewPhaseCode::JoinStandby)->count())->toBe(2)
        ->and($assignment->phases()->where('phase_code', CrewPhaseCode::Training)->first()?->details)
        ->toMatchArray(['provider' => 'Falck', 'course' => 'BOSIET']);

    $service->perform($company->id, $id, CrewMovementAction::MarkReady, [
        'occurred_at' => '2026-02-11 08:00:00',
    ], $user->id);
    $assignment = $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-02-12 08:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ], $user->id);

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel);
});

test('arrival can open p3 directly', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    $service = crewMovementService();

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $service->perform($company->id, $assignment->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-03-01 08:00:00',
    ], $user->id);
    $assignment = $service->perform($company->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-03-02 08:00:00',
        'next_phase' => 'p3',
    ], $user->id);

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::ReadyToJoin);
});

test('join vessel requires vessel and rank and does not require actual disembarkation', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Join Required');
    $service = crewMovementService();

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;
    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, ['occurred_at' => '2026-03-01 08:00:00'], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-03-02 08:00:00',
        'next_phase' => 'p3',
    ], $user->id);

    expect(fn () => $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-03-03 08:00:00',
    ], $user->id))->toThrow(CrewMovementException::class, 'Join vessel requires vessel_id and rank_id.');

    $assignment = $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-03-03 08:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ], $user->id);

    expect($assignment->currentPhase?->actual_end_at)->toBeNull()
        ->and($assignment->planned_signoff_at)->toBeNull();
});

test('planned sign-off does not close p4 and confirm preserves planned sign-off', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Plan Signoff');
    $service = crewMovementService();

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;
    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, ['occurred_at' => '2026-01-01 08:00:00'], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-01-02 08:00:00',
        'next_phase' => 'p3',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-01-03 08:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ], $user->id);

    $assignment = $service->perform($company->id, $id, CrewMovementAction::PlanSignoff, [
        'planned_signoff_at' => '2026-06-15 00:00:00',
    ], $user->id);

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Active)
        ->and($assignment->planned_signoff_at?->toDateString())->toBe('2026-06-15')
        ->and($assignment->currentPhase?->planned_end_at?->toDateString())->toBe('2026-06-15');

    $assignment = $service->perform($company->id, $id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-06-10 08:00:00',
        'next_phase' => 'p6',
    ], $user->id);

    expect($assignment->planned_signoff_at?->toDateString())->toBe('2026-06-15')
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::HomeRedeploy);
});

test('direct p4 to p6 is supported via confirm disembarkation', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Direct Home');
    $service = crewMovementService();

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;
    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, ['occurred_at' => '2026-01-01 08:00:00'], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-01-02 08:00:00',
        'next_phase' => 'p3',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-01-03 08:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ], $user->id);
    $assignment = $service->perform($company->id, $id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-03-01 08:00:00',
        'next_phase' => 'p6',
    ], $user->id);

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::HomeRedeploy);
});

test('start demob standby uses shared disembarkation handler', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Demob Start');
    $service = crewMovementService();

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;
    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, ['occurred_at' => '2026-01-01 08:00:00'], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-01-02 08:00:00',
        'next_phase' => 'p3',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-01-03 08:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ], $user->id);
    $assignment = $service->perform($company->id, $id, CrewMovementAction::StartDemobStandby, [
        'occurred_at' => '2026-03-01 08:00:00',
    ], $user->id);

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::DemobStandby);
});

test('assignment closes only from p6 and cancelling p4 is rejected', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Cancel Rules');
    $service = crewMovementService();

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;
    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, ['occurred_at' => '2026-01-01 08:00:00'], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-01-02 08:00:00',
        'next_phase' => 'p3',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-01-03 08:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ], $user->id);

    expect(fn () => $service->perform($company->id, $id, CrewMovementAction::CloseAssignment, [
        'occurred_at' => '2026-01-04 08:00:00',
    ], $user->id))->toThrow(CrewMovementException::class);

    expect(fn () => $service->perform($company->id, $id, CrewMovementAction::CancelAssignment, [
        'reason' => 'Client cancelled',
    ], $user->id))->toThrow(CrewMovementException::class, 'Assignments on vessel cannot be cancelled directly.');
});

test('unsupported transfer redeploy and correction actions return clear errors', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    $service = crewMovementService();
    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);

    foreach ([
        CrewMovementAction::TransferVessel,
        CrewMovementAction::Redeploy,
        CrewMovementAction::CorrectMovement,
    ] as $action) {
        expect(fn () => $service->perform($company->id, $assignment->id, $action, [], $user->id))
            ->toThrow(CrewMovementException::class);
    }
});

test('second active assignment for the same employee is rejected', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    $service = crewMovementService();

    $first = $service->createDraft($company->id, $employee->id, [], $user->id);
    $service->perform($company->id, $first->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-01-01 08:00:00',
    ], $user->id);

    expect(fn () => $service->createDraft($company->id, $employee->id, [], $user->id))
        ->toThrow(CrewMovementException::class, 'Employee already has an active assignment');
});

test('cross-company assignment access is rejected', function () {
    ['company' => $companyA, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    ['company' => $companyB] = makeCrewAssignmentFixtures();
    $service = crewMovementService();

    $assignment = $service->createDraft($companyA->id, $employee->id, [], $user->id);

    expect(fn () => $service->perform($companyB->id, $assignment->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-01-01 08:00:00',
    ], $user->id))->toThrow(CrewMovementException::class, 'Crew assignment not found for this company.');
});

test('invalid transition rolls back all changes', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    $service = crewMovementService();

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;
    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-01-01 08:00:00',
    ], $user->id);

    $beforePhases = CrewAssignmentPhase::query()->where('crew_assignment_id', $id)->count();
    $beforeStatus = $assignment->fresh()->status;

    expect(fn () => $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-01-02 08:00:00',
        'vessel_id' => makeCrewMovementVessel('Bad Join')->id,
        'rank_id' => $employee->rank_id,
    ], $user->id))->toThrow(CrewMovementException::class);

    expect(CrewAssignmentPhase::query()->where('crew_assignment_id', $id)->count())->toBe($beforePhases)
        ->and($assignment->fresh()->status)->toBe($beforeStatus)
        ->and($assignment->fresh()->currentPhase?->phase_code)->toBe(CrewPhaseCode::TravelIn);
});

test('simulated failure after phase completion rolls back phase and assignment updates', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();

    $real = app(CrewAssignmentInvariantGuard::class);
    $calls = 0;
    $guard = Mockery::mock(CrewAssignmentInvariantGuard::class);
    $guard->shouldReceive('assertValid')->andReturnUsing(function ($assignment) use (&$calls, $real): void {
        $calls++;
        if ($calls > 1) {
            throw CrewMovementException::make('Simulated failure', 'simulated_failure');
        }
        $real->assertValid($assignment);
    });
    app()->instance(CrewAssignmentInvariantGuard::class, $guard);

    $service = app(CrewMovementService::class);
    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;

    $calls = 0;
    expect(fn () => $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-01-01 08:00:00',
    ], $user->id))->toThrow(CrewMovementException::class, 'Simulated failure');

    $fresh = CrewAssignment::query()->findOrFail($id);
    expect($fresh->status)->toBe(CrewAssignmentStatus::Draft)
        ->and($fresh->phases()->count())->toBe(1)
        ->and($fresh->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and($fresh->currentPhase?->status)->toBe(CrewPhaseStatus::Planned);
});

test('actor ids are stored and activity logging occurs', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    $service = crewMovementService();

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $assignment = $service->perform($company->id, $assignment->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-01-01 08:00:00',
    ], $user->id);

    expect($assignment->updated_by)->toBe($user->id)
        ->and($assignment->currentPhase?->started_by)->toBe($user->id);

    $logged = Activity::query()
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $assignment->id)
        ->exists();

    expect($logged)->toBeTrue();
});

test('cancel assignment works before p4', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    $service = crewMovementService();

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $service->perform($company->id, $assignment->id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-01-01 08:00:00',
    ], $user->id);
    $assignment = $service->perform($company->id, $assignment->id, CrewMovementAction::CancelAssignment, [
        'reason' => 'Medical hold',
        'occurred_at' => '2026-01-02 08:00:00',
    ], $user->id);

    expect($assignment->status)->toBe(CrewAssignmentStatus::Cancelled)
        ->and($assignment->closed_at)->not->toBeNull()
        ->and($assignment->remarks)->toContain('Medical hold')
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Cancelled);
});

test('phase sequence remains consistent across movements', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user] = makeCrewAssignmentFixtures();
    $service = crewMovementService();

    $assignment = $service->createDraft($company->id, $employee->id, [], $user->id);
    $id = $assignment->id;
    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, ['occurred_at' => '2026-01-01 08:00:00'], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-01-02 08:00:00',
        'next_phase' => 'p2a',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-01-03 08:00:00',
    ], $user->id);
    $assignment = $service->perform($company->id, $id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-01-04 08:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    $sequences = $assignment->phases()->ordered()->pluck('sequence')->all();

    expect($sequences)->toBe([1, 2, 3, 4, 5]);
});
