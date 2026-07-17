<?php

use App\Enums\CrewMovementCorrectionStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\CrewMovementCorrection;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Support\CrewMovements\Corrections\RequestCrewMovementCorrection;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, company: Company, employee: Employee, rank: Rank, assignment: CrewAssignment, vessel: Vessel}
 */
function authorizeCorrectionRequester(): array
{
    $fixtures = makeCrewAssignmentFixtures();
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.corrections.view',
        'crew_operations.corrections.request',
    ]);

    $vessel = makeCrewMovementVessel('Correction Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );

    return [...$fixtures, 'assignment' => $assignment, 'vessel' => $vessel];
}

test('correction request creates pending record without changing official phase data', function () {
    ['user' => $user, 'assignment' => $assignment] = authorizeCorrectionRequester();
    $phase = $assignment->currentPhase;
    $originalStart = $phase->actual_start_at->toIso8601String();
    $proposedStart = $phase->actual_start_at->copy()->addDay()->timezone($assignment->company->timezone ?? 'UTC')->format('Y-m-d H:i');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.corrections.store', $assignment), [
            'crew_assignment_phase_id' => $phase->id,
            'proposed_values' => [
                'actual_start_at' => $proposedStart,
                'remarks' => 'Vessel log shows later join.',
            ],
            'reason' => 'Vessel log review',
        ])
        ->assertRedirect();

    $correction = CrewMovementCorrection::query()->where('crew_assignment_id', $assignment->id)->first();

    expect($correction)->not->toBeNull()
        ->and($correction->status)->toBe(CrewMovementCorrectionStatus::Pending)
        ->and($correction->reason)->toBe('Vessel log review')
        ->and($correction->requested_by)->toBe($user->id);

    $phase->refresh();
    expect($phase->actual_start_at->toIso8601String())->toBe($originalStart)
        ->and($phase->remarks)->toBeNull();
});

test('correction request rejects no-op proposals', function () {
    ['user' => $user, 'assignment' => $assignment, 'company' => $company] = authorizeCorrectionRequester();
    $phase = $assignment->currentPhase;
    $currentStart = $phase->actual_start_at->copy()->timezone($company->timezone)->format('Y-m-d H:i');

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.corrections.store', $assignment), [
            'crew_assignment_phase_id' => $phase->id,
            'proposed_values' => [
                'actual_start_at' => $currentStart,
            ],
            'reason' => 'Same values',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHasErrors('correction');

    expect(CrewMovementCorrection::query()->count())->toBe(0);
});

test('only one pending correction is allowed per phase', function () {
    ['user' => $user, 'assignment' => $assignment, 'company' => $company] = authorizeCorrectionRequester();
    $phase = $assignment->currentPhase;
    $proposedStart = $phase->actual_start_at->copy()->addDay()->timezone($company->timezone)->format('Y-m-d H:i');

    app(RequestCrewMovementCorrection::class)->handle(
        $assignment,
        $phase,
        $user,
        ['actual_start_at' => $proposedStart],
        'First request',
    );

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.corrections.store', $assignment), [
            'crew_assignment_phase_id' => $phase->id,
            'proposed_values' => [
                'actual_start_at' => $phase->actual_start_at->copy()->addDays(2)->timezone($company->timezone)->format('Y-m-d H:i'),
            ],
            'reason' => 'Second request',
        ])
        ->assertSessionHasErrors('correction');

    expect(CrewMovementCorrection::query()->pending()->count())->toBe(1);
});

test('assignment show includes correction summary props', function () {
    ['user' => $user, 'assignment' => $assignment, 'company' => $company] = authorizeCorrectionRequester();
    $phase = $assignment->currentPhase;
    $proposedStart = $phase->actual_start_at->copy()->addDay()->timezone($company->timezone)->format('Y-m-d H:i');

    app(RequestCrewMovementCorrection::class)->handle(
        $assignment,
        $phase,
        $user,
        ['actual_start_at' => $proposedStart],
        'Need review',
    );

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/show')
            ->has('corrections.pending', 1)
            ->where('corrections.pending_count', 1)
            ->where('can.request_correction', true));
});

test('corrections index is company scoped', function () {
    ['user' => $user, 'assignment' => $assignment, 'company' => $company] = authorizeCorrectionRequester();
    $phase = $assignment->currentPhase;
    $proposedStart = $phase->actual_start_at->copy()->addDay()->timezone($company->timezone)->format('Y-m-d H:i');

    app(RequestCrewMovementCorrection::class)->handle(
        $assignment,
        $phase,
        $user,
        ['actual_start_at' => $proposedStart],
        'Need review',
    );

    ['user' => $otherUser, 'company' => $otherCompany, 'employee' => $otherEmployee, 'rank' => $otherRank] = makeCrewAssignmentFixtures();
    $otherUser->update(['current_company_id' => $otherCompany->id]);
    grantCompanyPermissions($otherUser, $otherCompany, [
        'crew_operations.corrections.view',
    ]);
    $otherVessel = makeCrewMovementVessel('Other Vessel');
    $otherAssignment = makeActiveOnVesselAssignment($otherCompany, $otherEmployee, $otherRank, $otherVessel);
    app(RequestCrewMovementCorrection::class)->handle(
        $otherAssignment,
        $otherAssignment->currentPhase,
        $otherUser,
        ['actual_start_at' => $otherAssignment->currentPhase->actual_start_at->copy()->addDay()->timezone($otherCompany->timezone)->format('Y-m-d H:i')],
        'Other company',
    );

    $this->actingAs($user)
        ->get(route('organization.crew-movement-corrections.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-movement-corrections/index')
            ->has('corrections', 1)
            ->where('status_counts.pending', 1));
});
