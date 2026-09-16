<?php

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Exceptions\CrewMovementException;
use App\Models\Company;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\Hotel;
use App\Models\Rank;
use App\Models\RoomType;
use App\Models\User;
use App\Support\CrewMovements\CrewMovementService;

/**
 * @return array{user: User, company: Company, employee: Employee, rank: Rank}
 */
function makeCrewMovementAccommodationFixtures(): array
{
    $fixtures = makeCrewAssignmentFixtures();

    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.movements.perform',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    return $fixtures;
}

function startActivePreMobilisationAssignment(array $fixtures): CrewAssignment
{
    return app(CrewMovementService::class)->startAssignment(
        $fixtures['company']->id,
        $fixtures['employee']->id,
        [
            'rank_id' => $fixtures['rank']->id,
            'stage_started_at' => '2026-09-15 08:00:00',
        ],
        $fixtures['user']->id,
    );
}

test('record arrival creates pre join hotel stay', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementAccommodationFixtures();
    $assignment = startActivePreMobilisationAssignment(compact('user', 'company', 'employee', 'rank'));
    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Royal Rose']);
    $roomType = RoomType::factory()->create(['company_id' => $company->id, 'name' => 'Single Room']);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-09-16 10:30:00',
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'check_in_date' => '2026-09-16',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $assignment->refresh()->load('currentPhase');
    $stay = CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->first();

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($stay)->not->toBeNull()
        ->and($stay->company_id)->toBe($company->id)
        ->and($stay->stay_type)->toBe(CrewAccommodationStayType::PreJoin)
        ->and($stay->accommodation_status)->toBe(CrewAccommodationStatus::Hotel)
        ->and($stay->hotel_id)->toBe($hotel->id)
        ->and($stay->room_type_id)->toBe($roomType->id)
        ->and($stay->check_in_date?->toDateString())->toBe('2026-09-16')
        ->and($stay->check_out_date)->toBeNull()
        ->and($stay->started_from_phase_id)->toBe($assignment->current_phase_id)
        ->and($stay->created_by)->toBe($user->id);
});

test('record arrival creates explicit no accommodation stay', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementAccommodationFixtures();
    $assignment = startActivePreMobilisationAssignment(compact('user', 'company', 'employee', 'rank'));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-09-16 10:30:00',
            'accommodation_status' => CrewAccommodationStatus::NoAccommodation->value,
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $stay = CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->first();

    expect($stay)->not->toBeNull()
        ->and($stay->stay_type)->toBe(CrewAccommodationStayType::PreJoin)
        ->and($stay->accommodation_status)->toBe(CrewAccommodationStatus::NoAccommodation)
        ->and($stay->hotel_id)->toBeNull()
        ->and($stay->room_type_id)->toBeNull()
        ->and($stay->check_in_date)->toBeNull()
        ->and($stay->check_out_date)->toBeNull();
});

test('record arrival without accommodation payload remains backward compatible', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementAccommodationFixtures();
    $assignment = startActivePreMobilisationAssignment(compact('user', 'company', 'employee', 'rank'));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-09-16 10:30:00',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    expect(CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);
});

test('record arrival hotel path requires hotel and check in date', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementAccommodationFixtures();
    $assignment = startActivePreMobilisationAssignment(compact('user', 'company', 'employee', 'rank'));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-09-16 10:30:00',
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
        ])
        ->assertSessionHasErrors(['hotel_id', 'check_in_date']);
});

test('record arrival rejects foreign inactive and invalid check in dates', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementAccommodationFixtures();
    ['company' => $companyB] = makeCrewAssignmentFixtures();
    $assignment = startActivePreMobilisationAssignment(compact('user', 'company', 'employee', 'rank'));
    $foreignHotel = Hotel::factory()->create(['company_id' => $companyB->id]);
    $foreignRoomType = RoomType::factory()->create(['company_id' => $companyB->id]);
    $inactiveHotel = Hotel::factory()->create(['company_id' => $company->id, 'is_active' => false]);
    $inactiveRoomType = RoomType::factory()->create(['company_id' => $company->id, 'is_active' => false]);
    $validHotel = Hotel::factory()->create(['company_id' => $company->id, 'is_active' => true]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-09-16 10:30:00',
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $foreignHotel->id,
            'check_in_date' => '2026-09-16',
        ])
        ->assertSessionHasErrors('hotel_id');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-09-16 10:30:00',
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $validHotel->id,
            'room_type_id' => $foreignRoomType->id,
            'check_in_date' => '2026-09-16',
        ])
        ->assertSessionHasErrors('room_type_id');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-09-16 10:30:00',
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $inactiveHotel->id,
            'check_in_date' => '2026-09-16',
        ])
        ->assertSessionHasErrors('hotel_id');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-09-16 10:30:00',
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $validHotel->id,
            'room_type_id' => $inactiveRoomType->id,
            'check_in_date' => '2026-09-16',
        ])
        ->assertSessionHasErrors('room_type_id');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-09-16 23:50:00',
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $validHotel->id,
            'check_in_date' => '2026-09-15',
        ])
        ->assertSessionHasErrors('check_in_date');

    $assignment->refresh()->load('currentPhase');
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and(CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);
});

test('join vessel closes open pre join hotel stay and enters p4', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementAccommodationFixtures();
    $vessel = makeCrewMovementVessel('Join Vessel Hotel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);
    $hotel = Hotel::factory()->create(['company_id' => $company->id]);
    $stay = CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-16',
        'check_out_date' => null,
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::JoinVessel->value,
            'occurred_at' => '2026-09-19 08:00:00',
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'check_out_date' => '2026-09-19',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $assignment->refresh()->load('currentPhase');
    $stay->refresh();

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($stay->check_out_date?->toDateString())->toBe('2026-09-19');
});

test('join vessel with no accommodation decision succeeds without checkout', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementAccommodationFixtures();
    $vessel = makeCrewMovementVessel('No Hotel Join', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation,
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::JoinVessel->value,
            'occurred_at' => '2026-09-19 08:00:00',
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $assignment->refresh()->load('currentPhase');
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel);
});

test('join vessel with missing accommodation remains allowed', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementAccommodationFixtures();
    $vessel = makeCrewMovementVessel('Legacy Join', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::JoinVessel->value,
            'occurred_at' => '2026-09-19 08:00:00',
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    expect(CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);
});

test('join vessel checkout date validation rejects invalid dates and leaves p2a open', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementAccommodationFixtures();
    $vessel = makeCrewMovementVessel('Checkout Validation', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);
    $hotel = Hotel::factory()->create(['company_id' => $company->id]);
    $stay = CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-16',
        'check_out_date' => null,
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::JoinVessel->value,
            'occurred_at' => '2026-09-19 08:00:00',
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'check_out_date' => '2026-09-15',
        ])
        ->assertSessionHasErrors('check_out_date');

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::JoinVessel->value,
            'occurred_at' => '2026-09-19 08:00:00',
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'check_out_date' => '2026-09-20',
        ])
        ->assertSessionHasErrors('check_out_date');

    $assignment->refresh()->load('currentPhase');
    $stay->refresh();

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($stay->check_out_date)->toBeNull();

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::JoinVessel->value,
            'occurred_at' => '2026-09-19 08:00:00',
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'check_out_date' => '2026-09-18',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    expect($stay->fresh()->check_out_date?->toDateString())->toBe('2026-09-18');
});

test('training loop keeps the same open pre join hotel stay until join vessel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementAccommodationFixtures();
    $vessel = makeCrewMovementVessel('Training Loop Hotel', $company);
    $assignment = startActivePreMobilisationAssignment(compact('user', 'company', 'employee', 'rank'));
    $hotel = Hotel::factory()->create(['company_id' => $company->id]);

    app(CrewMovementService::class)->perform($company->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-16 10:30:00',
        'accommodation_status' => CrewAccommodationStatus::Hotel->value,
        'hotel_id' => $hotel->id,
        'check_in_date' => '2026-09-16',
    ], $user->id);

    $stay = CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->sole();

    $assignment->refresh()->load('currentPhase');

    app(CrewMovementService::class)->perform($company->id, $assignment->id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-09-17 09:00:00',
    ], $user->id);

    $assignment->refresh()->load('currentPhase');

    app(CrewMovementService::class)->perform($company->id, $assignment->id, CrewMovementAction::CompleteTraining, [
        'occurred_at' => '2026-09-18 16:00:00',
        'next_phase' => CrewPhaseCode::JoinStandby->value,
    ], $user->id);

    expect(CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(1)
        ->and($stay->fresh()->check_out_date)->toBeNull();

    app(CrewMovementService::class)->perform($company->id, $assignment->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-09-19 08:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'check_out_date' => '2026-09-19',
    ], $user->id);

    expect($stay->fresh()->check_out_date?->toDateString())->toBe('2026-09-19');
});

test('assignment show exposes accommodation summary and missing pre join warning context', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementAccommodationFixtures();
    $vessel = makeCrewMovementVessel('Show Accommodation', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);
    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Royal Rose']);
    CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-16',
        'check_out_date' => null,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organization/crew/show')
            ->has('assignment.accommodation', 1)
            ->where('assignment.accommodation.0.hotel_name', 'Royal Rose')
            ->where('assignment.movement_context.pre_join_accommodation.status', 'open_hotel')
        );

    ['employee' => $legacyEmployee] = makeCrewAssignmentFixtures();
    $legacyAssignment = makeCurrentCrewPhaseAssignment($company, $legacyEmployee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.show', $legacyAssignment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('assignment.movement_context.pre_join_accommodation.status', 'missing')
        );
});

test('record arrival rejects invalid explicit accommodation status at domain layer', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementAccommodationFixtures();
    $assignment = startActivePreMobilisationAssignment(compact('user', 'company', 'employee', 'rank'));
    $service = app(CrewMovementService::class);

    expect(fn () => $service->perform($company->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-16 10:30:00',
        'accommodation_status' => 'not_a_valid_status',
    ], $user->id))->toThrow(function (CrewMovementException $exception): void {
        expect($exception->getMessage())->toBe('Invalid accommodation status.')
            ->and($exception->errorCode)->toBe('invalid_accommodation_status');
    });

    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and(CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);
});

test('join vessel rejects multiple open pre join hotel stays without partial mutation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementAccommodationFixtures();
    $vessel = makeCrewMovementVessel('Multiple Open Stays', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);
    $hotelA = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Hotel A']);
    $hotelB = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Hotel B']);
    $stayA = CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotelA->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-16',
        'check_out_date' => null,
    ]);
    $stayB = CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotelB->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-17',
        'check_out_date' => null,
    ]);
    $service = app(CrewMovementService::class);

    expect(fn () => $service->perform($company->id, $assignment->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-09-19 08:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'check_out_date' => '2026-09-19',
    ], $user->id))->toThrow(function (CrewMovementException $exception): void {
        expect($exception->getMessage())->toBe(
            'Multiple open pre-join hotel stays were found. Resolve accommodation data before joining the vessel.',
        )->and($exception->errorCode)->toBe('pre_join_accommodation_integrity');
    });

    $assignment->refresh()->load('currentPhase');
    $stayA->refresh();
    $stayB->refresh();

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($stayA->check_out_date)->toBeNull()
        ->and($stayB->check_out_date)->toBeNull();
});

test('record arrival accepts nullable room type for hotel accommodation', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementAccommodationFixtures();
    $assignment = startActivePreMobilisationAssignment(compact('user', 'company', 'employee', 'rank'));
    $hotel = Hotel::factory()->create(['company_id' => $company->id]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-09-16 10:30:00',
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $hotel->id,
            'room_type_id' => null,
            'check_in_date' => '2026-09-16',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $stay = CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->first();

    expect($stay)->not->toBeNull()
        ->and($stay->room_type_id)->toBeNull();
});

test('failed record arrival accommodation validation leaves assignment unchanged', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementAccommodationFixtures();
    $assignment = startActivePreMobilisationAssignment(compact('user', 'company', 'employee', 'rank'));

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-09-16 10:30:00',
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => 999999,
            'check_in_date' => '2026-09-16',
        ])
        ->assertSessionHasErrors('hotel_id');

    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::PreMobilisation)
        ->and(CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);
});
