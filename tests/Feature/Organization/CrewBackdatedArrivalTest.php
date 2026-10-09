<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Exceptions\CrewMovementException;
use App\Models\Company;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Hotel;
use App\Models\Position;
use App\Models\User;
use App\Support\CrewMovements\CrewInitialArrivalBackdateGuard;
use App\Support\CrewMovements\CrewMovementService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Dubai'));
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Dubai'));
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

/**
 * @return array{user: User, company: Company, employee: Employee, rank: Position, service: CrewMovementService, assignment: CrewAssignment}
 */
function makeBackdatedArrivalFixtures(): array
{
    $fixtures = makeCrewAssignmentFixtures();
    $fixtures['company']->update(['timezone' => 'Asia/Dubai']);

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.update',
        'crew_operations.movements.perform',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    $service = app(CrewMovementService::class);
    $assignment = $service->startAssignment($fixtures['company']->id, $fixtures['employee']->id, [
        'position_id' => $fixtures['rank']->id,
        'stage_started_at' => '2026-10-08 10:00:00',
    ], $fixtures['user']->id);

    return [
        ...$fixtures,
        'service' => $service,
        'assignment' => $assignment,
    ];
}

test('backdated arrival before assignment creation succeeds for valid initial p0', function () {
    [
        'company' => $company,
        'user' => $user,
        'service' => $service,
        'assignment' => $assignment,
    ] = makeBackdatedArrivalFixtures();

    $originalCreatedAt = $assignment->created_at?->copy();
    $originalStartedAt = $assignment->started_at?->copy();

    expect(CrewInitialArrivalBackdateGuard::isEligible($assignment))->toBeTrue();

    $result = $service->perform($company->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-10-07 09:00:00',
        'next_phase' => CrewPhaseCode::JoinStandby->value,
        'accommodation_status' => 'no_accommodation',
    ], $user->id);

    $result->load('phases');
    $p0 = $result->phases->firstWhere('phase_code', CrewPhaseCode::PreMobilisation);
    $p2a = $result->currentPhase;

    expect($p2a?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($p2a?->actual_start_at?->timezone('Asia/Dubai')->toDateTimeString())->toBe('2026-10-07 09:00:00')
        ->and($p0?->actual_start_at?->timezone('Asia/Dubai')->toDateTimeString())->toBe('2026-10-07 09:00:00')
        ->and($p0?->actual_end_at?->timezone('Asia/Dubai')->toDateTimeString())->toBe('2026-10-07 09:00:00')
        ->and($p0?->actual_end_at?->lt($p0->actual_start_at))->toBeFalse()
        ->and($result->fresh()->created_at?->equalTo($originalCreatedAt))->toBeTrue()
        ->and($result->fresh()->started_at?->equalTo($originalStartedAt))->toBeTrue()
        ->and(EmployeeSeaService::query()->where('employee_id', $result->employee_id)->exists())->toBeFalse();
});

test('http record arrival accepts backdated initial p0 arrival', function () {
    [
        'user' => $user,
        'assignment' => $assignment,
    ] = makeBackdatedArrivalFixtures();

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-10-07 09:00:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
            'accommodation_status' => 'no_accommodation',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHasNoErrors();

    $assignment->refresh()->load(['currentPhase', 'phases']);
    $p0 = $assignment->phases->firstWhere('phase_code', CrewPhaseCode::PreMobilisation);

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($p0?->actual_start_at?->timezone('Asia/Dubai')->toDateTimeString())->toBe('2026-10-07 09:00:00')
        ->and($p0?->actual_end_at?->timezone('Asia/Dubai')->toDateTimeString())->toBe('2026-10-07 09:00:00');
});

test('backdated arrival reconciliation is recorded in the activity log', function () {
    [
        'company' => $company,
        'user' => $user,
        'service' => $service,
        'assignment' => $assignment,
    ] = makeBackdatedArrivalFixtures();

    $service->perform($company->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-10-07 09:00:00',
        'next_phase' => CrewPhaseCode::JoinStandby->value,
        'accommodation_status' => 'no_accommodation',
    ], $user->id);

    $log = Activity::query()
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $assignment->id)
        ->where('description', 'Initial pre-mobilisation phase reconciled for backdated arrival')
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->properties['event'])->toBe('crew_initial_arrival_backdated')
        ->and($log->properties['previous_phase_actual_start_at'])->toContain('2026-10-08 10:00:00')
        ->and($log->properties['adjusted_phase_actual_start_at'])->toContain('2026-10-07 09:00:00')
        ->and($log->properties['assignment_started_at_preserved'])->not->toBeNull()
        ->and($log->properties['assignment_created_at_preserved'])->not->toBeNull()
        ->and($log->company_id)->toBe($company->id);
});

test('backdated arrival overlapping a completed assignment is rejected', function () {
    [
        'company' => $company,
        'employee' => $employee,
        'rank' => $rank,
        'user' => $user,
        'service' => $service,
        'assignment' => $assignment,
    ] = makeBackdatedArrivalFixtures();

    $vessel = makeCrewMovementVessel('Prior Vessel', $company);
    $prior = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-PRIOR-001',
        'employee_id' => $employee->id,
        'position_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Completed,
        'started_at' => CarbonImmutable::parse('2026-09-01 08:00:00', 'Asia/Dubai'),
        'closed_at' => CarbonImmutable::parse('2026-10-07 18:00:00', 'Asia/Dubai'),
        'source' => 'manual',
    ]);
    CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $prior->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => $prior->started_at,
        'actual_end_at' => $prior->closed_at,
    ]);

    expect(fn () => $service->perform($company->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-10-07 09:00:00',
        'next_phase' => CrewPhaseCode::JoinStandby->value,
        'accommodation_status' => 'no_accommodation',
    ], $user->id))->toThrow(CrewMovementException::class, 'overlaps completed assignment');

    $assignment->refresh()->load('currentPhase');
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation);
});

test('backdating a later join vessel movement remains rejected', function () {
    [
        'company' => $company,
        'employee' => $employee,
        'rank' => $rank,
        'user' => $user,
        'service' => $service,
        'assignment' => $assignment,
    ] = makeBackdatedArrivalFixtures();

    $vessel = makeCrewMovementVessel('Join Chronology Vessel', $company);

    $service->perform($company->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-10-08 11:00:00',
        'next_phase' => CrewPhaseCode::JoinStandby->value,
        'accommodation_status' => 'no_accommodation',
    ], $user->id);

    setMappedCrewTourOfDutyDays($company, $rank, 90);

    expect(fn () => $service->perform($company->id, $assignment->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-10-07 08:00:00',
        'vessel_id' => $vessel->id,
        'position_id' => $rank->id,
        'planned_signoff_choice' => 'tour_of_duty',
    ], $user->id))->toThrow(CrewMovementException::class);
});

test('http rejects backdating after leaving initial p0', function () {
    [
        'user' => $user,
        'company' => $company,
        'service' => $service,
        'assignment' => $assignment,
    ] = makeBackdatedArrivalFixtures();

    $service->perform($company->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-10-08 11:00:00',
        'next_phase' => CrewPhaseCode::JoinStandby->value,
        'accommodation_status' => 'no_accommodation',
    ], $user->id);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::SendToTraining->value,
            'occurred_at' => '2026-10-07 08:00:00',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHasErrors(['occurred_at']);
});

test('legacy travel in backdated arrival remains rejected', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();
    $company->update(['timezone' => 'Asia/Dubai']);
    $vessel = makeCrewMovementVessel('Legacy Travel Vessel', $company);

    $assignment = makeCurrentCrewPhaseAssignment(
        $company,
        $employee,
        $rank,
        $vessel,
        CrewPhaseCode::TravelIn,
        ['started_at' => CarbonImmutable::parse('2026-10-08 10:00:00', 'Asia/Dubai')],
    );
    $assignment->currentPhase?->update([
        'actual_start_at' => CarbonImmutable::parse('2026-10-08 10:00:00', 'Asia/Dubai'),
    ]);
    $assignment->refresh()->load(['currentPhase', 'phases']);

    expect(CrewInitialArrivalBackdateGuard::isEligible($assignment))->toBeFalse();

    expect(fn () => app(CrewMovementService::class)->perform(
        $company->id,
        $assignment->id,
        CrewMovementAction::RecordArrival,
        [
            'occurred_at' => '2026-10-07 09:00:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
            'accommodation_status' => 'no_accommodation',
        ],
        $user->id,
    ))->toThrow(CrewMovementException::class, 'This date cannot be before the current phase started.');
});

test('backdated arrival with hotel accommodation keeps hotel validation', function () {
    [
        'user' => $user,
        'assignment' => $assignment,
    ] = makeBackdatedArrivalFixtures();

    $hotel = Hotel::factory()->create([
        'company_id' => $assignment->company_id,
        'name' => 'Backdate Hotel',
    ]);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-10-07 09:00:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
            'accommodation_status' => 'hotel',
            'hotel_id' => $hotel->id,
            'check_in_date' => '2026-10-06',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHasErrors(['check_in_date']);

    $this->actingAs($user)
        ->from(route('organization.crew-assignments.show', $assignment))
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-10-07 09:00:00',
            'next_phase' => CrewPhaseCode::JoinStandby->value,
            'accommodation_status' => 'hotel',
            'hotel_id' => $hotel->id,
            'check_in_date' => '2026-10-07',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment))
        ->assertSessionHasNoErrors();

    $stay = CrewAccommodationStay::query()
        ->where('crew_assignment_id', $assignment->id)
        ->first();

    expect($stay)->not->toBeNull()
        ->and($stay->check_in_date?->toDateString())->toBe('2026-10-07');
});
