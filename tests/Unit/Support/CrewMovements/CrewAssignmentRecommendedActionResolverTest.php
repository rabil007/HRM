<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMobilisationReadinessStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Support\CrewMovements\CrewAssignmentRecommendedActionResolver;
use App\Support\CrewMovements\CrewMobilisationReadinessResult;
use App\Support\CrewMovements\CrewMovementAvailableActions;
use App\Support\CrewMovements\CrewMovementService;

function recommendedActionForPhase(CrewPhaseCode $phase, CrewAssignmentStatus $status = CrewAssignmentStatus::Active): array
{
    $fixtures = makeCrewAssignmentFixtures();
    $assignment = app(CrewMovementService::class)->createDraft(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        ['rank_id' => $fixtures['rank']->id],
        $fixtures['user']->id,
    )->load(['currentPhase', 'employee', 'company']);

    if ($phase !== CrewPhaseCode::PreMobilisation) {
        $assignment->update(['status' => $status]);
        $assignment->currentPhase->update([
            'phase_code' => $phase,
            'status' => CrewPhaseStatus::Active,
            'actual_start_at' => now(),
        ]);
        $assignment->setRelation('currentPhase', $assignment->currentPhase->fresh());
    }

    $available = CrewMovementAvailableActions::for($assignment->fresh(['currentPhase']));
    $recommended = (new CrewAssignmentRecommendedActionResolver)->forAssignment(
        $assignment->fresh(['currentPhase', 'employee', 'company', 'nextAssignments']),
        $available,
    );

    return [$assignment->fresh(['currentPhase']), $available, $recommended];
}

it('recommends approve mobilisation for ready P0', function () {
    [$assignment, $available, $recommended] = recommendedActionForPhase(CrewPhaseCode::PreMobilisation, CrewAssignmentStatus::Draft);

    expect($recommended?->action)->toBe(CrewMovementAction::ApproveMobilisation->value)
        ->and($available)->toContain(CrewMovementAction::CancelAssignment->value)
        ->and($available)->toContain(CrewMovementAction::ApproveMobilisation->value);
});

it('recommends approve mobilisation when no document checks are configured', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $assignment = app(CrewMovementService::class)->createDraft(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        ['rank_id' => $fixtures['rank']->id],
        $fixtures['user']->id,
    )->load(['currentPhase', 'employee']);

    $readiness = new CrewMobilisationReadinessResult(
        status: CrewMobilisationReadinessStatus::Ready,
        checksClear: 0,
        checksTotal: 0,
        checks: [],
        problems: [],
        documentsHref: null,
        applies: true,
    );

    $available = CrewMovementAvailableActions::for($assignment);
    $recommended = (new CrewAssignmentRecommendedActionResolver)->forAssignment(
        $assignment,
        $available,
        $readiness,
    );

    expect($readiness->presentationLabel())->toBe('No Checks Configured')
        ->and($recommended?->type)->toBe('movement')
        ->and($recommended?->action)->toBe(CrewMovementAction::ApproveMobilisation->value)
        ->and($available)->toContain(CrewMovementAction::ApproveMobilisation->value);
});

it('recommends resolving readiness on P0 when checks fail without removing other actions', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $assignment = app(CrewMovementService::class)->createDraft(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        ['rank_id' => $fixtures['rank']->id],
        $fixtures['user']->id,
    )->load(['currentPhase', 'employee']);

    $readiness = new CrewMobilisationReadinessResult(
        status: CrewMobilisationReadinessStatus::NotReady,
        checksClear: 4,
        checksTotal: 6,
        checks: [],
        problems: [[
            'code' => 'document_missing',
            'severity' => 'critical',
            'label' => 'Seaman Book missing',
            'message' => 'Seaman Book is required and has no upload.',
            'document_type_id' => 1,
        ]],
        documentsHref: '/organization/documents/employees/1',
        applies: true,
    );

    $available = CrewMovementAvailableActions::for($assignment);
    $recommended = (new CrewAssignmentRecommendedActionResolver)->forAssignment(
        $assignment,
        $available,
        $readiness,
    );

    expect($recommended?->type)->toBe('readiness')
        ->and($recommended?->anywayAction)->toBe(CrewMovementAction::ApproveMobilisation->value)
        ->and($available)->toContain(CrewMovementAction::ApproveMobilisation->value)
        ->and($available)->toContain(CrewMovementAction::CancelAssignment->value);
});

it('recommends record arrival for P1', function () {
    [, $available, $recommended] = recommendedActionForPhase(CrewPhaseCode::TravelIn);

    expect($recommended?->action)->toBe(CrewMovementAction::RecordArrival->value)
        ->and($available)->toContain(CrewMovementAction::CancelAssignment->value);
});

it('recommends join vessel for P2A from allowed actions', function () {
    [, $available, $recommended] = recommendedActionForPhase(CrewPhaseCode::JoinStandby);

    expect($recommended?->action)->toBe(CrewMovementAction::JoinVessel->value)
        ->and($available)->toContain(CrewMovementAction::SendToTraining->value)
        ->and($available)->toContain(CrewMovementAction::JoinVessel->value);
});

it('recommends complete training for P2B', function () {
    [, , $recommended] = recommendedActionForPhase(CrewPhaseCode::Training);

    expect($recommended?->action)->toBe(CrewMovementAction::CompleteTraining->value);
});

it('recommends join vessel for P3', function () {
    [, , $recommended] = recommendedActionForPhase(CrewPhaseCode::ReadyToJoin);

    expect($recommended?->action)->toBe(CrewMovementAction::JoinVessel->value);
});

it('recommends confirm disembarkation for P4', function () {
    $fixtures = makeCrewAssignmentFixtures();
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        makeCrewMovementVessel('Rec P4 Vessel'),
    )->load(['currentPhase', 'employee', 'company']);

    $available = CrewMovementAvailableActions::for($assignment);
    $recommended = (new CrewAssignmentRecommendedActionResolver)->forAssignment($assignment, $available);

    expect($recommended?->action)->toBe(CrewMovementAction::ConfirmDisembarkation->value)
        ->and($available)->toContain(CrewMovementAction::PlanSignoff->value)
        ->and($available)->not->toContain(CrewMovementAction::CancelAssignment->value);
});

it('recommends travel home for P5', function () {
    [, $available, $recommended] = recommendedActionForPhase(CrewPhaseCode::DemobStandby);

    expect($recommended?->action)->toBe(CrewMovementAction::TravelHome->value)
        ->and($available)->toContain(CrewMovementAction::Redeploy->value);
});

it('recommends close assignment for P6', function () {
    [, $available, $recommended] = recommendedActionForPhase(CrewPhaseCode::HomeRedeploy);

    expect($recommended?->action)->toBe(CrewMovementAction::CloseAssignment->value)
        ->and($available)->toContain(CrewMovementAction::Redeploy->value);
});

it('never recommends an action that is not already allowed', function () {
    [$assignment, $available, $recommended] = recommendedActionForPhase(CrewPhaseCode::TravelIn);

    expect($recommended?->action)->not->toBeNull()
        ->and($available)->toContain($recommended->action);
});

it('does not recommend a movement the user cannot perform', function () {
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.assignments.cancel',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    $assignment = app(CrewMovementService::class)->createDraft(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        ['rank_id' => $fixtures['rank']->id],
        $fixtures['user']->id,
    )->load('currentPhase');

    $available = CrewMovementAvailableActions::for($assignment);
    $recommended = (new CrewAssignmentRecommendedActionResolver)->forAssignment(
        $assignment,
        $available,
        user: $fixtures['user'],
    );

    expect($available)->toContain(CrewMovementAction::ApproveMobilisation->value)
        ->and($recommended)->toBeNull();
});
