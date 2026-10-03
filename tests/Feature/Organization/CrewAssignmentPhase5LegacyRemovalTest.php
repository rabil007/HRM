<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewAssignmentSubmissionIntent;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\CrewAssignment;
use App\Support\CrewMovements\CrewMovementService;

test('CrewAssignmentStatus no longer accepts planned', function () {
    expect(CrewAssignmentStatus::tryFrom('planned'))->toBeNull()
        ->and(CrewAssignmentStatus::cases())->not->toContain(CrewAssignmentStatus::tryFrom('planned'))
        ->and(in_array('planned', CrewAssignmentStatus::values(), true))->toBeFalse();
});

test('CrewPhaseStatus Planned still exists for draft pre-mobilisation', function () {
    expect(CrewPhaseStatus::tryFrom('planned'))->toBe(CrewPhaseStatus::Planned)
        ->and(CrewPhaseStatus::Planned->value)->toBe('planned');
});

test('submission_intent plan fails validation and creates nothing', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Phase5 Plan Intent Vessel', $company);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
        'crew_operations.planning.create',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);

    expect(CrewAssignmentSubmissionIntent::tryFrom('plan'))->toBeNull()
        ->and(CrewAssignmentSubmissionIntent::createValues())->toBe(['start', 'draft']);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.store'), [
            'submission_intent' => 'plan',
            'employee_id' => $employee->id,
            'position_id' => $rank->id,
            'vessel_id' => $vessel->id,
            'planned_join_at' => '2026-10-10',
            'planned_signoff_at' => '2026-11-30',
        ])
        ->assertSessionHasErrors(['submission_intent']);

    expect(CrewAssignment::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('draft P0 can still use CrewPhaseStatus Planned', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Phase5 Draft P0 Vessel', $company);

    $draft = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-10-10',
        'planned_signoff_at' => '2026-11-30',
    ], $user->id);

    expect($draft->status)->toBe(CrewAssignmentStatus::Draft)
        ->and($draft->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and($draft->currentPhase?->status)->toBe(CrewPhaseStatus::Planned)
        ->and($draft->currentPhase?->actual_start_at)->toBeNull()
        ->and($draft->started_at)->toBeNull();
});
