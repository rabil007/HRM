<?php

use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Models\CrewAssignment;
use App\Models\Vessel;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewMovementAvailableActions;
use App\Support\CrewMovements\CrewMovementService;

/**
 * @return array{0: CrewAssignment, 1: CrewMovementService, 2: array{company: mixed, employee: mixed, rank: mixed, user: mixed}, 3: Vessel}
 */
function makePhasedAssignment(CrewPhaseCode $targetPhase): array
{
    $fixtures = makeCrewAssignmentFixtures();
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = $fixtures;
    $vessel = makeCrewMovementVessel('Actions '.$targetPhase->value);
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);

    if ($targetPhase === CrewPhaseCode::PreMobilisation) {
        return [$assignment->load('currentPhase'), $service, $fixtures, $vessel];
    }

    $id = $assignment->id;
    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-01-01 08:00:00',
    ], $user->id);

    if ($targetPhase === CrewPhaseCode::TravelIn) {
        return [$assignment->fresh(['currentPhase']), $service, $fixtures, $vessel];
    }

    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-01-05 10:00:00',
        'next_phase' => 'p2a',
    ], $user->id);

    if ($targetPhase === CrewPhaseCode::JoinStandby) {
        return [$assignment->fresh(['currentPhase']), $service, $fixtures, $vessel];
    }

    if ($targetPhase === CrewPhaseCode::Training) {
        $service->perform($company->id, $id, CrewMovementAction::SendToTraining, [
            'occurred_at' => '2026-01-06 09:00:00',
            'provider' => 'Academy',
            'course' => 'BOSIET',
        ], $user->id);

        return [$assignment->fresh(['currentPhase']), $service, $fixtures, $vessel];
    }

    $service->perform($company->id, $id, CrewMovementAction::MarkReady, [
        'occurred_at' => '2026-01-08 09:00:00',
    ], $user->id);

    if ($targetPhase === CrewPhaseCode::ReadyToJoin) {
        return [$assignment->fresh(['currentPhase']), $service, $fixtures, $vessel];
    }

    $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-01-10 12:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ], $user->id);

    if ($targetPhase === CrewPhaseCode::OnVessel) {
        return [$assignment->fresh(['currentPhase']), $service, $fixtures, $vessel];
    }

    $service->perform($company->id, $id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-04-01 08:00:00',
        'next_phase' => 'p5',
    ], $user->id);

    if ($targetPhase === CrewPhaseCode::DemobStandby) {
        return [$assignment->fresh(['currentPhase']), $service, $fixtures, $vessel];
    }

    $service->perform($company->id, $id, CrewMovementAction::TravelHome, [
        'occurred_at' => '2026-04-05 14:00:00',
    ], $user->id);

    return [$assignment->fresh(['currentPhase']), $service, $fixtures, $vessel];
}

test('available actions match guided menus for every phase', function (CrewPhaseCode $phase, array $expected) {
    [$assignment] = makePhasedAssignment($phase);

    expect(CrewMovementAvailableActions::for($assignment))->toBe($expected);
})->with([
    'draft p0' => [
        CrewPhaseCode::PreMobilisation,
        [
            CrewMovementAction::ApproveMobilisation->value,
            CrewMovementAction::CancelAssignment->value,
        ],
    ],
    'p1 travel in' => [
        CrewPhaseCode::TravelIn,
        [
            CrewMovementAction::RecordArrival->value,
            CrewMovementAction::CancelAssignment->value,
        ],
    ],
    'p2a join standby' => [
        CrewPhaseCode::JoinStandby,
        [
            CrewMovementAction::SendToTraining->value,
            CrewMovementAction::MarkReady->value,
            CrewMovementAction::JoinVessel->value,
            CrewMovementAction::CancelAssignment->value,
        ],
    ],
    'p2b training' => [
        CrewPhaseCode::Training,
        [
            CrewMovementAction::CompleteTraining->value,
            CrewMovementAction::CancelAssignment->value,
        ],
    ],
    'p3 ready to join' => [
        CrewPhaseCode::ReadyToJoin,
        [
            CrewMovementAction::JoinVessel->value,
            CrewMovementAction::CancelAssignment->value,
        ],
    ],
    'p4 on vessel' => [
        CrewPhaseCode::OnVessel,
        [
            CrewMovementAction::PlanSignoff->value,
            CrewMovementAction::ConfirmDisembarkation->value,
        ],
    ],
    'p5 demob' => [
        CrewPhaseCode::DemobStandby,
        [
            CrewMovementAction::TravelHome->value,
            CrewMovementAction::CancelAssignment->value,
        ],
    ],
    'p6 home' => [
        CrewPhaseCode::HomeRedeploy,
        [
            CrewMovementAction::CloseAssignment->value,
            CrewMovementAction::CancelAssignment->value,
        ],
    ],
]);

test('p1 no longer exposes start join standby or mark ready', function () {
    [$assignment] = makePhasedAssignment(CrewPhaseCode::TravelIn);

    expect(CrewMovementAvailableActions::for($assignment))
        ->not->toContain(CrewMovementAction::StartJoinStandby->value)
        ->not->toContain(CrewMovementAction::MarkReady->value);
});

test('p2b no longer exposes mark ready', function () {
    [$assignment] = makePhasedAssignment(CrewPhaseCode::Training);

    expect(CrewMovementAvailableActions::for($assignment))
        ->not->toContain(CrewMovementAction::MarkReady->value);
});

test('days in phase is a whole number', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Days Whole');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel)
        ->load(['currentPhase', 'phases', 'company', 'employee', 'rank', 'vessel']);

    $detail = CrewAssignmentPresenter::detail($assignment);

    expect($detail['days_in_phase'])->toBeInt()
        ->and($detail['days_onboard'])->toBeInt()
        ->and($detail['movement_context']['company_timezone'])->toBe('Asia/Dubai')
        ->and($detail['movement_context']['assignment_no'])->toBe($assignment->assignment_no);
});
