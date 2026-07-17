<?php

use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewMovementAvailableActions;
use App\Support\CrewMovements\CrewMovementService;

test('available actions for draft pre-mobilisation', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id)->load('currentPhase');

    expect(CrewMovementAvailableActions::for($assignment))->toBe([
        CrewMovementAction::ApproveMobilisation->value,
        CrewMovementAction::CancelAssignment->value,
    ]);
});

test('available actions for on-vessel exclude cancel', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Presenter Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    expect(CrewMovementAvailableActions::for($assignment))
        ->toContain(CrewMovementAction::PlanSignoff->value)
        ->toContain(CrewMovementAction::ConfirmDisembarkation->value)
        ->not->toContain(CrewMovementAction::CancelAssignment->value)
        ->not->toContain(CrewMovementAction::TransferVessel->value);
});

test('presenter separates planned and actual dates', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Presenter Dates Vessel');
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'planned_join_at' => '2026-01-01',
        'planned_signoff_at' => '2026-06-01',
    ])->load(['employee', 'rank', 'vessel', 'client', 'currentPhase', 'phases', 'company', 'planningAssignment', 'companyVisaType']);

    $detail = CrewAssignmentPresenter::detail($assignment);
    $onVessel = collect($detail['phase_timeline'])->firstWhere('phase_code', CrewPhaseCode::OnVessel->value);

    expect($detail['planned_join_at'])->toBe('2026-01-01')
        ->and($detail['planned_signoff_at'])->toBe('2026-06-01')
        ->and($detail['actual_join_at'])->toBe($onVessel['actual_start_at'])
        ->and($detail['actual_disembarkation_at'])->toBeNull()
        ->and($detail['actual_join_at'])->not->toBe($detail['planned_signoff_at'])
        ->and($detail['current_phase']['code'])->toBe(CrewPhaseCode::OnVessel->value)
        ->and($detail['phase_timeline'])->not->toBeEmpty()
        ->and($detail['available_actions'])->toBeArray()
        ->and($detail['warnings'])->toBeArray();
});

test('list presenter includes warnings payload shape', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id)->load(['employee', 'rank', 'vessel', 'client', 'currentPhase', 'company']);

    $assignment->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

    $item = CrewAssignmentPresenter::listItem($assignment->fresh([
        'employee',
        'rank',
        'vessel',
        'client',
        'currentPhase',
        'company',
    ]));

    expect($item['warnings'])->toBeArray()
        ->and($item['available_actions'])->toBe([
            CrewMovementAction::ApproveMobilisation->value,
            CrewMovementAction::CancelAssignment->value,
        ]);

    if ($item['warnings'] !== []) {
        expect($item['warnings'][0])->toHaveKeys(['code', 'severity', 'label', 'message', 'date']);
    }
});
