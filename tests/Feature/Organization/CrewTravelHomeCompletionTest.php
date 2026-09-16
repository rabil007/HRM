<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exceptions\CrewMovementException;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Support\CrewMovements\CrewAssignmentStatusResolver;
use App\Support\CrewMovements\CrewMovementAvailableActions;
use App\Support\CrewMovements\CrewMovementService;
use Carbon\CarbonImmutable;

/**
 * @return array{
 *     company: Company,
 *     employee: Employee,
 *     rank: Rank,
 *     user: User,
 *     vessel: Vessel,
 *     assignment: CrewAssignment,
 *     service: CrewMovementService
 * }
 */
function makeActiveP5Assignment(): array
{
    $fixtures = makeCrewAssignmentFixtures();
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = $fixtures;
    $vessel = makeCrewMovementVessel('Travel Home Completion');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);

    $id = $assignment->id;
    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-01-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-01-05 10:00:00',
        'next_phase' => 'p2a',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-01-10 12:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-04-01 08:00:00',
        'next_phase' => 'p5',
    ], $user->id);

    return [
        ...$fixtures,
        'vessel' => $vessel,
        'assignment' => $assignment->fresh(['currentPhase', 'phases']),
        'service' => $service,
    ];
}

test('return home and close completes p5 p6 and assignment with actual return-home timestamp', function () {
    ['company' => $company, 'user' => $user, 'assignment' => $assignment, 'service' => $service] = makeActiveP5Assignment();

    $result = $service->perform($company->id, $assignment->id, CrewMovementAction::TravelHome, [
        'occurred_at' => '2026-09-16 19:00:00',
        'completion_intent' => 'close',
    ], $user->id);

    $result->load(['currentPhase', 'phases']);

    $p5 = $result->phases->firstWhere('phase_code', CrewPhaseCode::DemobStandby);
    $p6Phases = $result->phases->where('phase_code', CrewPhaseCode::HomeRedeploy);

    expect($result->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($result->closed_at?->toDateTimeString())->toBe('2026-09-16 19:00:00')
        ->and($result->currentPhase?->phase_code)->toBe(CrewPhaseCode::HomeRedeploy)
        ->and($result->currentPhase?->status)->toBe(CrewPhaseStatus::Completed)
        ->and($p5?->status)->toBe(CrewPhaseStatus::Completed)
        ->and($p5?->actual_end_at?->toDateTimeString())->toBe('2026-09-16 19:00:00')
        ->and($p6Phases)->toHaveCount(1)
        ->and($p6Phases->first()?->actual_start_at?->toDateTimeString())->toBe('2026-09-16 19:00:00')
        ->and($p6Phases->first()?->actual_end_at?->toDateTimeString())->toBe('2026-09-16 19:00:00');
});

test('return home defaults to close when completion intent is omitted', function () {
    ['company' => $company, 'user' => $user, 'assignment' => $assignment, 'service' => $service] = makeActiveP5Assignment();

    $result = $service->perform($company->id, $assignment->id, CrewMovementAction::TravelHome, [
        'occurred_at' => '2026-09-16 19:00:00',
    ], $user->id);

    expect($result->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($result->closed_at)->not->toBeNull();
});

test('keep open for redeployment leaves active p6 assignment', function () {
    ['company' => $company, 'user' => $user, 'assignment' => $assignment, 'service' => $service] = makeActiveP5Assignment();

    $result = $service->perform($company->id, $assignment->id, CrewMovementAction::TravelHome, [
        'occurred_at' => '2026-09-16 19:00:00',
        'completion_intent' => 'redeploy',
    ], $user->id);

    $result->load('currentPhase');
    $available = CrewMovementAvailableActions::for($result);

    expect($result->status)->toBe(CrewAssignmentStatus::Active)
        ->and($result->closed_at)->toBeNull()
        ->and($result->currentPhase?->phase_code)->toBe(CrewPhaseCode::HomeRedeploy)
        ->and($result->currentPhase?->status)->toBe(CrewPhaseStatus::Active)
        ->and($available)->toContain(CrewMovementAction::Redeploy->value)
        ->and($available)->toContain(CrewMovementAction::CloseAssignment->value);
});

test('return home and close removes active assignment conflict for next cycle', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user, 'rank' => $rank, 'assignment' => $assignment, 'service' => $service] = makeActiveP5Assignment();

    $service->perform($company->id, $assignment->id, CrewMovementAction::TravelHome, [
        'occurred_at' => '2026-09-16 19:00:00',
        'completion_intent' => 'close',
    ], $user->id);

    $next = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    expect($next->status)->toBe(CrewAssignmentStatus::Draft);
});

test('completed return home enables in home operational status', function () {
    ['company' => $company, 'employee' => $employee, 'user' => $user, 'assignment' => $assignment, 'service' => $service] = makeActiveP5Assignment();

    $service->perform($company->id, $assignment->id, CrewMovementAction::TravelHome, [
        'occurred_at' => '2026-09-10 19:00:00',
        'completion_intent' => 'close',
    ], $user->id);

    $employee->refresh();
    $status = app(CrewAssignmentStatusResolver::class)->forEmployee(
        $employee,
        CarbonImmutable::parse('2026-09-16', $company->timezone ?? 'UTC'),
        true,
    );

    expect($status['status'])->toBe('in_home')
        ->and($status['has_active_assignment'])->toBeFalse()
        ->and($status['in_home_days'])->toBe(6);
});

test('travel home cannot run from invalid phase', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Invalid Travel Home');
    $service = app(CrewMovementService::class);
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    expect(fn () => $service->perform($company->id, $assignment->id, CrewMovementAction::TravelHome, [
        'occurred_at' => '2026-09-16 19:00:00',
    ], $user->id))->toThrow(CrewMovementException::class);
});

test('travel home cannot close an already completed assignment', function () {
    ['company' => $company, 'user' => $user, 'assignment' => $assignment, 'service' => $service] = makeActiveP5Assignment();

    $service->perform($company->id, $assignment->id, CrewMovementAction::TravelHome, [
        'occurred_at' => '2026-09-16 19:00:00',
        'completion_intent' => 'close',
    ], $user->id);

    expect(fn () => $service->perform($company->id, $assignment->id, CrewMovementAction::TravelHome, [
        'occurred_at' => '2026-09-17 19:00:00',
        'completion_intent' => 'close',
    ], $user->id))->toThrow(CrewMovementException::class);
});

test('standalone close assignment still works from active p6', function () {
    ['company' => $company, 'user' => $user, 'assignment' => $assignment, 'service' => $service] = makeActiveP5Assignment();

    $service->perform($company->id, $assignment->id, CrewMovementAction::TravelHome, [
        'occurred_at' => '2026-09-16 19:00:00',
        'completion_intent' => 'redeploy',
    ], $user->id);

    $result = $service->perform($company->id, $assignment->id, CrewMovementAction::CloseAssignment, [
        'occurred_at' => '2026-09-20 08:00:00',
    ], $user->id);

    expect($result->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($result->closed_at?->toDateTimeString())->toBe('2026-09-20 08:00:00');
});

test('invalid travel home timestamp does not partially mutate p5', function () {
    ['company' => $company, 'user' => $user, 'assignment' => $assignment, 'service' => $service] = makeActiveP5Assignment();

    $p5Id = $assignment->currentPhase?->id;
    $phaseCountBefore = $assignment->phases()->count();

    try {
        $service->perform($company->id, $assignment->id, CrewMovementAction::TravelHome, [
            'occurred_at' => '2026-01-01 08:00:00',
            'completion_intent' => 'close',
        ], $user->id);
    } catch (CrewMovementException) {
        // expected when occurred_at is before current phase start
    }

    $assignment->refresh()->load('currentPhase');

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->id)->toBe($p5Id)
        ->and($assignment->currentPhase?->status)->toBe(CrewPhaseStatus::Active)
        ->and($assignment->phases()->count())->toBe($phaseCountBefore);
});

test('users without movement permission cannot return home and close', function () {
    ['company' => $company, 'user' => $user, 'assignment' => $assignment] = makeActiveP5Assignment();

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::TravelHome->value,
            'occurred_at' => '2026-09-16 19:00:00',
            'completion_intent' => 'close',
        ])
        ->assertForbidden();
});

test('cross-company travel home is rejected', function () {
    ['company' => $company, 'user' => $user] = makeActiveP5Assignment();
    ['company' => $otherCompany, 'employee' => $otherEmployee, 'rank' => $otherRank] = makeCrewAssignmentFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.movements.perform',
    ]);
    $user->update(['current_company_id' => $company->id]);

    $foreign = app(CrewMovementService::class)->createDraft($otherCompany->id, $otherEmployee->id, [
        'rank_id' => $otherRank->id,
    ], $user->id);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $foreign), [
            'action' => CrewMovementAction::TravelHome->value,
            'occurred_at' => '2026-09-16 19:00:00',
            'completion_intent' => 'close',
        ])
        ->assertNotFound();
});

test('direct p4 to p6 disembarkation regression remains available', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Direct P6');
    $service = app(CrewMovementService::class);

    $assignment = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id);
    $id = $assignment->id;

    $service->perform($company->id, $id, CrewMovementAction::ApproveMobilisation, [
        'occurred_at' => '2026-01-01 08:00:00',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-01-05 10:00:00',
        'next_phase' => 'p2a',
    ], $user->id);
    $service->perform($company->id, $id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-01-10 12:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
    ], $user->id);

    $result = $service->perform($company->id, $id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-04-01 08:00:00',
        'next_phase' => 'p6',
    ], $user->id);

    expect($result->currentPhase?->phase_code)->toBe(CrewPhaseCode::HomeRedeploy)
        ->and($result->status)->toBe(CrewAssignmentStatus::Active)
        ->and(CrewMovementAvailableActions::for($result))->toContain(CrewMovementAction::CloseAssignment->value);
});
