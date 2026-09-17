<?php

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
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
use App\Models\Rank;
use App\Models\RoomType;
use App\Models\User;
use App\Support\CrewAccommodation\CrewAccommodationService;
use App\Support\CrewMovements\Actions\VoidCrewAssignment;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewAssignmentVoidGuard;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewMovements\CurrentCrewQuery;
use App\Support\CrewMovements\CurrentCrewRequestFilters;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    freezeCrewMovementTestClock();
});

afterEach(function (): void {
    restoreCrewMovementTestClock();
});

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
    $roomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Single Room',
    ]);

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
    $foreignRoomType = RoomType::factory()->create([
        'company_id' => $companyB->id,
        'hotel_id' => $foreignHotel->id,
    ]);
    $inactiveHotel = Hotel::factory()->create(['company_id' => $company->id, 'is_active' => false]);
    $validHotel = Hotel::factory()->create(['company_id' => $company->id, 'is_active' => true]);
    $inactiveRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $validHotel->id,
        'is_active' => false,
    ]);

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

    $legacyRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => null,
        'is_active' => true,
        'name' => 'Legacy Room',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-09-16 10:30:00',
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $validHotel->id,
            'room_type_id' => $legacyRoomType->id,
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

test('record arrival rejects room type from another hotel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementAccommodationFixtures();
    $assignment = startActivePreMobilisationAssignment(compact('user', 'company', 'employee', 'rank'));
    $hotelA = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Hotel A']);
    $hotelB = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Hotel B']);
    $foreignRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotelB->id,
        'name' => 'Foreign Room',
    ]);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-09-16 10:30:00',
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $hotelA->id,
            'room_type_id' => $foreignRoomType->id,
            'check_in_date' => '2026-09-16',
        ])
        ->assertSessionHasErrors('room_type_id');

    expect(CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);
});

test('record arrival allows null room type when hotel is selected', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewMovementAccommodationFixtures();
    $assignment = startActivePreMobilisationAssignment(compact('user', 'company', 'employee', 'rank'));
    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Royal Rose']);

    $this->actingAs($user)
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::RecordArrival->value,
            'occurred_at' => '2026-09-16 10:30:00',
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $hotel->id,
            'check_in_date' => '2026-09-16',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $stay = CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->first();

    expect($stay)->not->toBeNull()
        ->and($stay->hotel_id)->toBe($hotel->id)
        ->and($stay->room_type_id)->toBeNull();
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

function makeActiveOnVesselAssignmentForAccommodation(array $fixtures): CrewAssignment
{
    $vessel = makeCrewMovementVessel('Post-Signoff Flow', $fixtures['company']);

    return makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
}

function makeActiveP5AssignmentWithPostSignoffHotel(array $fixtures, ?array $stayOverrides = null): array
{
    $assignment = makeActiveOnVesselAssignmentForAccommodation($fixtures);
    $hotel = Hotel::factory()->create(['company_id' => $fixtures['company']->id, 'name' => 'City Seasons']);

    app(CrewMovementService::class)->perform(
        $fixtures['company']->id,
        $assignment->id,
        CrewMovementAction::ConfirmDisembarkation,
        [
            'occurred_at' => '2026-11-30 09:30:00',
            'next_phase' => CrewPhaseCode::DemobStandby->value,
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $hotel->id,
            'check_in_date' => '2026-11-30',
            ...($stayOverrides ?? []),
        ],
        $fixtures['user']->id,
    );

    $assignment->refresh()->load('currentPhase');
    $stay = CrewAccommodationStay::query()
        ->where('crew_assignment_id', $assignment->id)
        ->where('stay_type', CrewAccommodationStayType::PostSignoff)
        ->first();

    return [$assignment, $hotel, $stay];
}

test('confirm disembarkation to p5 creates post signoff hotel stay', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    $assignment = makeActiveOnVesselAssignmentForAccommodation($fixtures);
    $hotel = Hotel::factory()->create(['company_id' => $fixtures['company']->id, 'name' => 'City Seasons']);
    $roomType = RoomType::factory()->create([
        'company_id' => $fixtures['company']->id,
        'hotel_id' => $hotel->id,
        'name' => 'Twin Room',
    ]);

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::ConfirmDisembarkation->value,
            'occurred_at' => '2026-11-30 23:30:00',
            'next_phase' => CrewPhaseCode::DemobStandby->value,
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $hotel->id,
            'room_type_id' => $roomType->id,
            'check_in_date' => '2026-11-30',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $assignment->refresh()->load('currentPhase');
    $stay = CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->first();

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::DemobStandby)
        ->and($stay)->not->toBeNull()
        ->and($stay->company_id)->toBe($fixtures['company']->id)
        ->and($stay->stay_type)->toBe(CrewAccommodationStayType::PostSignoff)
        ->and($stay->accommodation_status)->toBe(CrewAccommodationStatus::Hotel)
        ->and($stay->hotel_id)->toBe($hotel->id)
        ->and($stay->room_type_id)->toBe($roomType->id)
        ->and($stay->check_in_date?->toDateString())->toBe('2026-11-30')
        ->and($stay->check_out_date)->toBeNull()
        ->and($stay->started_from_phase_id)->toBe($assignment->current_phase_id)
        ->and($stay->created_by)->toBe($fixtures['user']->id);
});

test('confirm disembarkation to p5 creates explicit no accommodation stay', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    $assignment = makeActiveOnVesselAssignmentForAccommodation($fixtures);

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::ConfirmDisembarkation->value,
            'occurred_at' => '2026-11-30 09:30:00',
            'next_phase' => CrewPhaseCode::DemobStandby->value,
            'accommodation_status' => CrewAccommodationStatus::NoAccommodation->value,
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $stay = CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->first();

    expect($stay)->not->toBeNull()
        ->and($stay->stay_type)->toBe(CrewAccommodationStayType::PostSignoff)
        ->and($stay->accommodation_status)->toBe(CrewAccommodationStatus::NoAccommodation)
        ->and($stay->hotel_id)->toBeNull()
        ->and($stay->room_type_id)->toBeNull()
        ->and($stay->check_in_date)->toBeNull()
        ->and($stay->check_out_date)->toBeNull();
});

test('direct confirm disembarkation to p6 creates no post signoff stay', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    $assignment = makeActiveOnVesselAssignmentForAccommodation($fixtures);

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::ConfirmDisembarkation->value,
            'occurred_at' => '2026-11-30 09:30:00',
            'next_phase' => CrewPhaseCode::HomeRedeploy->value,
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => Hotel::factory()->create(['company_id' => $fixtures['company']->id])->id,
            'check_in_date' => '2026-11-30',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::HomeRedeploy)
        ->and(CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);
});

test('legacy confirm disembarkation to p5 without accommodation payload remains backward compatible', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    $assignment = makeActiveOnVesselAssignmentForAccommodation($fixtures);

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::ConfirmDisembarkation->value,
            'occurred_at' => '2026-11-30 09:30:00',
            'next_phase' => CrewPhaseCode::DemobStandby->value,
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::DemobStandby)
        ->and(CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('assignment.movement_context.post_signoff_accommodation.status', 'missing')
        );
});

test('confirm disembarkation hotel path validation rejects invalid payloads and leaves p4 active', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    ['company' => $companyB] = makeCrewAssignmentFixtures();
    $assignment = makeActiveOnVesselAssignmentForAccommodation($fixtures);
    $foreignHotel = Hotel::factory()->create(['company_id' => $companyB->id]);
    $foreignRoomType = RoomType::factory()->create([
        'company_id' => $companyB->id,
        'hotel_id' => $foreignHotel->id,
    ]);
    $inactiveHotel = Hotel::factory()->create(['company_id' => $fixtures['company']->id, 'is_active' => false]);
    $validHotel = Hotel::factory()->create(['company_id' => $fixtures['company']->id, 'is_active' => true]);
    $inactiveRoomType = RoomType::factory()->create([
        'company_id' => $fixtures['company']->id,
        'hotel_id' => $validHotel->id,
        'is_active' => false,
    ]);
    $payloadBase = [
        'action' => CrewMovementAction::ConfirmDisembarkation->value,
        'occurred_at' => '2026-11-30 09:30:00',
        'next_phase' => CrewPhaseCode::DemobStandby->value,
        'accommodation_status' => CrewAccommodationStatus::Hotel->value,
    ];

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), $payloadBase)
        ->assertSessionHasErrors(['hotel_id', 'check_in_date']);

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            ...$payloadBase,
            'hotel_id' => $foreignHotel->id,
            'check_in_date' => '2026-11-30',
        ])
        ->assertSessionHasErrors('hotel_id');

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            ...$payloadBase,
            'hotel_id' => $validHotel->id,
            'room_type_id' => $foreignRoomType->id,
            'check_in_date' => '2026-11-30',
        ])
        ->assertSessionHasErrors('room_type_id');

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            ...$payloadBase,
            'hotel_id' => $inactiveHotel->id,
            'check_in_date' => '2026-11-30',
        ])
        ->assertSessionHasErrors('hotel_id');

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            ...$payloadBase,
            'hotel_id' => $validHotel->id,
            'room_type_id' => $inactiveRoomType->id,
            'check_in_date' => '2026-11-30',
        ])
        ->assertSessionHasErrors('room_type_id');

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            ...$payloadBase,
            'hotel_id' => $validHotel->id,
            'check_in_date' => '2026-11-29',
        ])
        ->assertSessionHasErrors('check_in_date');

    $assignment->refresh()->load('currentPhase');
    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and(CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            ...$payloadBase,
            'hotel_id' => $validHotel->id,
            'check_in_date' => '2026-11-30',
            'room_type_id' => null,
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));
});

test('failed confirm disembarkation accommodation validation rolls back p4 completion and sea service sync', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    $assignment = makeActiveOnVesselAssignmentForAccommodation($fixtures);
    $service = app(CrewMovementService::class);

    expect(fn () => $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-11-30 09:30:00',
        'next_phase' => CrewPhaseCode::DemobStandby->value,
        'accommodation_status' => CrewAccommodationStatus::Hotel->value,
    ], $fixtures['user']->id))->toThrow(CrewMovementException::class);

    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($assignment->currentPhase?->status->value)->toBe('active')
        ->and(EmployeeSeaService::query()->where('crew_assignment_phase_id', $assignment->current_phase_id)->count())->toBe(0);
});

test('confirm disembarkation rejects duplicate post signoff hotel stay before movement mutation', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    $assignment = makeActiveOnVesselAssignmentForAccommodation($fixtures);
    $existingHotel = Hotel::factory()->create(['company_id' => $fixtures['company']->id, 'name' => 'Existing Post-Signoff']);
    $newHotel = Hotel::factory()->create(['company_id' => $fixtures['company']->id, 'name' => 'New Post-Signoff']);
    $existingStay = CrewAccommodationStay::factory()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $existingHotel->id,
        'stay_type' => CrewAccommodationStayType::PostSignoff,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-11-28',
        'check_out_date' => null,
        'started_from_phase_id' => $assignment->current_phase_id,
    ]);
    $service = app(CrewMovementService::class);

    expect(fn () => $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-11-30 09:30:00',
        'next_phase' => CrewPhaseCode::DemobStandby->value,
        'accommodation_status' => CrewAccommodationStatus::Hotel->value,
        'hotel_id' => $newHotel->id,
        'check_in_date' => '2026-11-30',
    ], $fixtures['user']->id))->toThrow(function (CrewMovementException $exception): void {
        expect($exception->getMessage())->toBe('An open post-sign-off hotel stay already exists for this assignment.')
            ->and($exception->errorCode)->toBe('post_signoff_accommodation_exists');
    });

    $assignment->refresh()->load(['currentPhase', 'phases']);
    $existingStay->refresh();

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and($assignment->currentPhase?->status->value)->toBe('active')
        ->and($assignment->phases->contains('phase_code', CrewPhaseCode::DemobStandby))->toBeFalse()
        ->and($existingStay->check_out_date)->toBeNull()
        ->and(CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(1)
        ->and(EmployeeSeaService::query()->where('crew_assignment_phase_id', $assignment->current_phase_id)->count())->toBe(0);
});

test('confirm disembarkation rejects new hotel when post signoff no accommodation decision exists', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    $assignment = makeActiveOnVesselAssignmentForAccommodation($fixtures);
    CrewAccommodationStay::factory()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'stay_type' => CrewAccommodationStayType::PostSignoff,
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation,
        'started_from_phase_id' => $assignment->current_phase_id,
    ]);
    $hotel = Hotel::factory()->create(['company_id' => $fixtures['company']->id, 'name' => 'City Seasons']);
    $service = app(CrewMovementService::class);

    expect(fn () => $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-11-30 09:30:00',
        'next_phase' => CrewPhaseCode::DemobStandby->value,
        'accommodation_status' => CrewAccommodationStatus::Hotel->value,
        'hotel_id' => $hotel->id,
        'check_in_date' => '2026-11-30',
    ], $fixtures['user']->id))->toThrow(function (CrewMovementException $exception): void {
        expect($exception->errorCode)->toBe('post_signoff_accommodation_exists');
    });

    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and(CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(1);
});

test('confirm disembarkation rejects duplicate post signoff no accommodation decision', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    $assignment = makeActiveOnVesselAssignmentForAccommodation($fixtures);
    CrewAccommodationStay::factory()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'stay_type' => CrewAccommodationStayType::PostSignoff,
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation,
        'started_from_phase_id' => $assignment->current_phase_id,
    ]);
    $service = app(CrewMovementService::class);

    expect(fn () => $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-11-30 09:30:00',
        'next_phase' => CrewPhaseCode::DemobStandby->value,
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation->value,
    ], $fixtures['user']->id))->toThrow(function (CrewMovementException $exception): void {
        expect($exception->errorCode)->toBe('post_signoff_accommodation_exists');
    });

    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and(CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(1);
});

test('confirm disembarkation rejects no accommodation when open post signoff hotel exists', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    $assignment = makeActiveOnVesselAssignmentForAccommodation($fixtures);
    $hotel = Hotel::factory()->create(['company_id' => $fixtures['company']->id, 'name' => 'Existing Post-Signoff']);
    CrewAccommodationStay::factory()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PostSignoff,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-11-28',
        'check_out_date' => null,
        'started_from_phase_id' => $assignment->current_phase_id,
    ]);
    $service = app(CrewMovementService::class);

    expect(fn () => $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-11-30 09:30:00',
        'next_phase' => CrewPhaseCode::DemobStandby->value,
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation->value,
    ], $fixtures['user']->id))->toThrow(function (CrewMovementException $exception): void {
        expect($exception->errorCode)->toBe('post_signoff_accommodation_exists');
    });

    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and(CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(1);
});

test('return home closes open post signoff hotel stay and completes p5 to p6', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    [$assignment, , $stay] = makeActiveP5AssignmentWithPostSignoffHotel($fixtures);

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::TravelHome->value,
            'occurred_at' => '2026-12-05 18:00:00',
            'check_out_date' => '2026-12-05',
            'completion_intent' => 'close',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $assignment->refresh()->load(['currentPhase', 'phases']);
    $stay->refresh();
    $p5 = $assignment->phases->firstWhere('phase_code', CrewPhaseCode::DemobStandby);

    expect($assignment->status->value)->toBe('completed')
        ->and($assignment->closed_at?->toDateTimeString())->toBe('2026-12-05 18:00:00')
        ->and($p5?->status->value)->toBe('completed')
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::HomeRedeploy)
        ->and($stay->check_out_date?->toDateString())->toBe('2026-12-05');
});

test('return home and close assignment keeps accommodation checkout atomic with assignment completion', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    [$assignment, , $stay] = makeActiveP5AssignmentWithPostSignoffHotel($fixtures);
    $service = app(CrewMovementService::class);

    $result = $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::TravelHome, [
        'occurred_at' => '2026-12-05 18:00:00',
        'check_out_date' => '2026-12-05',
        'completion_intent' => 'close',
    ], $fixtures['user']->id);

    expect($result->status->value)->toBe('completed')
        ->and($result->closed_at?->toDateTimeString())->toBe('2026-12-05 18:00:00')
        ->and($stay->fresh()->check_out_date?->toDateString())->toBe('2026-12-05');
});

test('keep open for redeployment still closes post signoff hotel stay', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    [$assignment, , $stay] = makeActiveP5AssignmentWithPostSignoffHotel($fixtures);
    $service = app(CrewMovementService::class);

    $result = $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::TravelHome, [
        'occurred_at' => '2026-12-05 18:00:00',
        'check_out_date' => '2026-12-05',
        'completion_intent' => 'redeploy',
    ], $fixtures['user']->id);

    $result->load('currentPhase');

    expect($result->status->value)->toBe('active')
        ->and($result->closed_at)->toBeNull()
        ->and($result->currentPhase?->phase_code)->toBe(CrewPhaseCode::HomeRedeploy)
        ->and($result->currentPhase?->status->value)->toBe('active')
        ->and($stay->fresh()->check_out_date?->toDateString())->toBe('2026-12-05');
});

test('return home with no accommodation decision succeeds without checkout', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    $assignment = makeActiveOnVesselAssignmentForAccommodation($fixtures);

    app(CrewMovementService::class)->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-11-30 09:30:00',
        'next_phase' => CrewPhaseCode::DemobStandby->value,
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation->value,
    ], $fixtures['user']->id);

    $assignment->refresh()->load('currentPhase');
    $stay = CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->sole();

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::TravelHome->value,
            'occurred_at' => '2026-12-05 18:00:00',
            'completion_intent' => 'close',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    expect($stay->fresh()->check_out_date)->toBeNull()
        ->and($stay->accommodation_status)->toBe(CrewAccommodationStatus::NoAccommodation);
});

test('return home with missing legacy accommodation remains allowed', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    $assignment = makeActiveOnVesselAssignmentForAccommodation($fixtures);

    app(CrewMovementService::class)->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-11-30 09:30:00',
        'next_phase' => CrewPhaseCode::DemobStandby->value,
    ], $fixtures['user']->id);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-assignments.show', $assignment->fresh()))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('assignment.movement_context.post_signoff_accommodation.status', 'missing')
        );

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::TravelHome->value,
            'occurred_at' => '2026-12-05 18:00:00',
            'completion_intent' => 'close',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    expect(CrewAccommodationStay::query()->where('crew_assignment_id', $assignment->id)->count())->toBe(0);
});

test('return home checkout date validation rejects invalid dates and leaves p5 open', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    [$assignment, , $stay] = makeActiveP5AssignmentWithPostSignoffHotel($fixtures);

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::TravelHome->value,
            'occurred_at' => '2026-12-05 18:00:00',
            'check_out_date' => '2026-11-29',
            'completion_intent' => 'close',
        ])
        ->assertSessionHasErrors('check_out_date');

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::TravelHome->value,
            'occurred_at' => '2026-12-05 18:00:00',
            'check_out_date' => '2026-12-06',
            'completion_intent' => 'close',
        ])
        ->assertSessionHasErrors('check_out_date');

    $assignment->refresh()->load('currentPhase');
    $stay->refresh();

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::DemobStandby)
        ->and($assignment->status->value)->toBe('active')
        ->and($stay->check_out_date)->toBeNull();

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::TravelHome->value,
            'occurred_at' => '2026-12-05 18:00:00',
            'check_out_date' => '2026-12-04',
            'completion_intent' => 'close',
        ])
        ->assertRedirect(route('organization.crew-assignments.show', $assignment));

    expect($stay->fresh()->check_out_date?->toDateString())->toBe('2026-12-04');
});

test('return home rejects multiple open post signoff hotel stays without partial mutation', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    $assignment = makeActiveOnVesselAssignmentForAccommodation($fixtures);
    $hotelA = Hotel::factory()->create(['company_id' => $fixtures['company']->id, 'name' => 'Hotel A']);
    $hotelB = Hotel::factory()->create(['company_id' => $fixtures['company']->id, 'name' => 'Hotel B']);

    app(CrewMovementService::class)->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-11-30 09:30:00',
        'next_phase' => CrewPhaseCode::DemobStandby->value,
    ], $fixtures['user']->id);

    $assignment->refresh()->load('currentPhase');
    $stayA = CrewAccommodationStay::factory()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotelA->id,
        'stay_type' => CrewAccommodationStayType::PostSignoff,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-11-30',
        'check_out_date' => null,
        'started_from_phase_id' => $assignment->current_phase_id,
    ]);
    $stayB = CrewAccommodationStay::factory()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotelB->id,
        'stay_type' => CrewAccommodationStayType::PostSignoff,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-12-01',
        'check_out_date' => null,
        'started_from_phase_id' => $assignment->current_phase_id,
    ]);

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::TravelHome->value,
            'occurred_at' => '2026-12-05 18:00:00',
            'check_out_date' => '2026-12-05',
            'completion_intent' => 'close',
        ])
        ->assertSessionHasErrors('check_out_date');

    $assignment->refresh()->load('currentPhase');
    $stayA->refresh();
    $stayB->refresh();

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::DemobStandby)
        ->and($assignment->status->value)->toBe('active')
        ->and($stayA->check_out_date)->toBeNull()
        ->and($stayB->check_out_date)->toBeNull();
});

test('assignment show accommodation summary includes pre join and post signoff stays', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    $assignment = startActivePreMobilisationAssignment($fixtures);
    $preJoinHotel = Hotel::factory()->create(['company_id' => $fixtures['company']->id, 'name' => 'Royal Rose']);

    app(CrewMovementService::class)->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-16 10:30:00',
        'accommodation_status' => CrewAccommodationStatus::Hotel->value,
        'hotel_id' => $preJoinHotel->id,
        'check_in_date' => '2026-09-16',
    ], $fixtures['user']->id);

    $vessel = makeCrewMovementVessel('History Vessel', $fixtures['company']);
    app(CrewMovementService::class)->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-09-19 08:00:00',
        'vessel_id' => $vessel->id,
        'rank_id' => $fixtures['rank']->id,
        'check_out_date' => '2026-09-19',
    ], $fixtures['user']->id);

    app(CrewMovementService::class)->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-11-30 09:30:00',
        'next_phase' => CrewPhaseCode::DemobStandby->value,
        'accommodation_status' => CrewAccommodationStatus::Hotel->value,
        'hotel_id' => Hotel::factory()->create(['company_id' => $fixtures['company']->id, 'name' => 'City Seasons'])->id,
        'check_in_date' => '2026-11-30',
    ], $fixtures['user']->id);

    $assignment->refresh();

    $summary = app(CrewAccommodationService::class)->assignmentAccommodationSummary($assignment);

    expect($summary[0]['stay_type'])->toBe('pre_join')
        ->and($summary[1]['stay_type'])->toBe('post_signoff');

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('assignment.accommodation', 2)
            ->where('assignment.accommodation.0.stay_type', 'pre_join')
            ->where('assignment.accommodation.1.stay_type', 'post_signoff')
        );
});

test('assignment accommodation summary orders stays by phase sequence instead of id', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    $assignment = makeActiveOnVesselAssignmentForAccommodation($fixtures);
    $preJoinPhase = CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-09-16 08:00:00',
        'actual_end_at' => '2026-09-19 08:00:00',
    ]);
    $postSignoffPhase = CrewAssignmentPhase::query()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::DemobStandby,
        'sequence' => 5,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-11-30 09:30:00',
        'actual_end_at' => '2026-12-05 18:00:00',
    ]);
    $hotelPostSignoff = Hotel::factory()->create(['company_id' => $fixtures['company']->id, 'name' => 'Later Phase Hotel']);
    $hotelPreJoin = Hotel::factory()->create(['company_id' => $fixtures['company']->id, 'name' => 'Earlier Phase Hotel']);

    $laterPhaseStay = CrewAccommodationStay::factory()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotelPostSignoff->id,
        'stay_type' => CrewAccommodationStayType::PostSignoff,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-11-30',
        'check_out_date' => '2026-12-05',
        'started_from_phase_id' => $postSignoffPhase->id,
    ]);
    $earlierPhaseStay = CrewAccommodationStay::factory()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotelPreJoin->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-16',
        'check_out_date' => '2026-09-19',
        'started_from_phase_id' => $preJoinPhase->id,
    ]);

    expect($laterPhaseStay->id)->toBeLessThan($earlierPhaseStay->id);

    $summary = app(CrewAccommodationService::class)->assignmentAccommodationSummary($assignment);

    expect($summary)->toHaveCount(2)
        ->and($summary[0]['id'])->toBe($earlierPhaseStay->id)
        ->and($summary[0]['stay_type'])->toBe('pre_join')
        ->and($summary[1]['id'])->toBe($laterPhaseStay->id)
        ->and($summary[1]['stay_type'])->toBe('post_signoff');

    $assignment->load(['accommodationStays.hotel', 'accommodationStays.roomType']);

    $this->actingAs($fixtures['user'])
        ->get(route('organization.crew-assignments.show', $assignment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('assignment.accommodation.0.id', $earlierPhaseStay->id)
            ->where('assignment.accommodation.1.id', $laterPhaseStay->id)
        );
});

function makeActiveP2AAssignmentWithPreJoinHotel(array $fixtures): array
{
    $assignment = startActivePreMobilisationAssignment($fixtures);
    $hotel = Hotel::factory()->create(['company_id' => $fixtures['company']->id, 'name' => 'Royal Rose']);

    app(CrewMovementService::class)->perform(
        $fixtures['company']->id,
        $assignment->id,
        CrewMovementAction::RecordArrival,
        [
            'occurred_at' => '2026-09-16 10:30:00',
            'accommodation_status' => CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $hotel->id,
            'check_in_date' => '2026-09-16',
        ],
        $fixtures['user']->id,
    );

    $assignment->refresh()->load('currentPhase');
    $stay = CrewAccommodationStay::query()
        ->where('crew_assignment_id', $assignment->id)
        ->where('stay_type', CrewAccommodationStayType::PreJoin)
        ->first();

    return [$assignment, $hotel, $stay];
}

test('p5 redeploy closes source post signoff hotel stay and creates destination assignment', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    [$assignment, , $stay] = makeActiveP5AssignmentWithPostSignoffHotel($fixtures);
    $service = app(CrewMovementService::class);

    $destination = $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::Redeploy, [
        'occurred_at' => '2026-12-05 09:00:00',
        'starting_phase' => CrewPhaseCode::PreMobilisation->value,
        'source_check_out_date' => '2026-12-04',
    ], $fixtures['user']->id);

    $assignment->refresh();
    $stay->refresh();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($stay->check_out_date?->toDateString())->toBe('2026-12-04')
        ->and($destination->previous_assignment_id)->toBe($assignment->id)
        ->and($destination->status)->toBe(CrewAssignmentStatus::Draft);
});

test('p5 redeploy rollback leaves source stay open when destination validation fails', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    [$assignment, , $stay] = makeActiveP5AssignmentWithPostSignoffHotel($fixtures);
    $service = app(CrewMovementService::class);

    expect(fn () => $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::Redeploy, [
        'occurred_at' => '2026-12-05 09:00:00',
        'starting_phase' => CrewPhaseCode::OnVessel->value,
        'source_check_out_date' => '2026-12-04',
    ], $fixtures['user']->id))->toThrow(CrewMovementException::class);

    $assignment->refresh()->load('currentPhase');
    $stay->refresh();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::DemobStandby)
        ->and($stay->check_out_date)->toBeNull()
        ->and(CrewAssignment::query()->where('previous_assignment_id', $assignment->id)->exists())->toBeFalse();
});

test('redeploy to p2a creates destination pre join hotel stay', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    [$assignment, $sourceHotel, $sourceStay] = makeActiveP5AssignmentWithPostSignoffHotel($fixtures);
    $destinationHotel = Hotel::factory()->create(['company_id' => $fixtures['company']->id, 'name' => 'Destination Hotel']);
    $roomType = RoomType::factory()->create([
        'company_id' => $fixtures['company']->id,
        'hotel_id' => $destinationHotel->id,
        'name' => 'Twin Room',
    ]);

    $destination = app(CrewMovementService::class)->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::Redeploy, [
        'occurred_at' => '2026-12-05 09:00:00',
        'starting_phase' => CrewPhaseCode::JoinStandby->value,
        'source_check_out_date' => '2026-12-05',
        'accommodation_status' => CrewAccommodationStatus::Hotel->value,
        'hotel_id' => $destinationHotel->id,
        'room_type_id' => $roomType->id,
        'check_in_date' => '2026-12-05',
    ], $fixtures['user']->id);

    $sourceStay->refresh();
    $destinationStay = CrewAccommodationStay::query()
        ->where('crew_assignment_id', $destination->id)
        ->where('stay_type', CrewAccommodationStayType::PreJoin)
        ->first();

    expect($sourceStay->check_out_date?->toDateString())->toBe('2026-12-05')
        ->and($destination->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($destinationStay)->not->toBeNull()
        ->and($destinationStay->hotel_id)->toBe($destinationHotel->id)
        ->and($destinationStay->started_from_phase_id)->toBe($destination->current_phase_id);
});

test('redeploy to p2a creates explicit destination no accommodation stay', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    [$assignment, , $sourceStay] = makeActiveP5AssignmentWithPostSignoffHotel($fixtures);

    $destination = app(CrewMovementService::class)->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::Redeploy, [
        'occurred_at' => '2026-12-05 09:00:00',
        'starting_phase' => CrewPhaseCode::JoinStandby->value,
        'source_check_out_date' => '2026-12-05',
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation->value,
    ], $fixtures['user']->id);

    $destinationStay = CrewAccommodationStay::query()
        ->where('crew_assignment_id', $destination->id)
        ->first();

    expect($sourceStay->fresh()->check_out_date?->toDateString())->toBe('2026-12-05')
        ->and($destinationStay)->not->toBeNull()
        ->and($destinationStay->stay_type)->toBe(CrewAccommodationStayType::PreJoin)
        ->and($destinationStay->accommodation_status)->toBe(CrewAccommodationStatus::NoAccommodation);
});

test('redeploy to p0 handles source accommodation without creating destination stay', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    [$assignment, , $stay] = makeActiveP5AssignmentWithPostSignoffHotel($fixtures);

    $destination = app(CrewMovementService::class)->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::Redeploy, [
        'occurred_at' => '2026-12-05 09:00:00',
        'starting_phase' => CrewPhaseCode::PreMobilisation->value,
        'source_check_out_date' => '2026-12-05',
    ], $fixtures['user']->id);

    expect($stay->fresh()->check_out_date?->toDateString())->toBe('2026-12-05')
        ->and(CrewAccommodationStay::query()->where('crew_assignment_id', $destination->id)->count())->toBe(0);
});

test('redeploy to p4 handles source accommodation without creating destination pre join stay', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    $fixtures['rank']->update(['max_tour_of_duty_days' => 90]);
    [$assignment, , $stay] = makeActiveP5AssignmentWithPostSignoffHotel($fixtures);
    $vessel = makeCrewMovementVessel('Redeploy P4 Vessel', $fixtures['company']);

    $destination = app(CrewMovementService::class)->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::Redeploy, [
        'occurred_at' => '2026-12-05 09:00:00',
        'starting_phase' => CrewPhaseCode::OnVessel->value,
        'source_check_out_date' => '2026-12-05',
        'vessel_id' => $vessel->id,
        'rank_id' => $fixtures['rank']->id,
        'planned_signoff_choice' => 'tour_of_duty',
    ], $fixtures['user']->id);

    expect($stay->fresh()->check_out_date?->toDateString())->toBe('2026-12-05')
        ->and($destination->currentPhase?->phase_code)->toBe(CrewPhaseCode::OnVessel)
        ->and(CrewAccommodationStay::query()->where('crew_assignment_id', $destination->id)->count())->toBe(0);
});

test('p5 redeploy without legacy source accommodation remains allowed', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    $assignment = makeActiveOnVesselAssignmentForAccommodation($fixtures);

    app(CrewMovementService::class)->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-11-30 09:30:00',
        'next_phase' => CrewPhaseCode::DemobStandby->value,
    ], $fixtures['user']->id);

    $destination = app(CrewMovementService::class)->perform($fixtures['company']->id, $assignment->fresh()->id, CrewMovementAction::Redeploy, [
        'occurred_at' => '2026-12-05 09:00:00',
        'starting_phase' => CrewPhaseCode::PreMobilisation->value,
    ], $fixtures['user']->id);

    expect($assignment->fresh()->status)->toBe(CrewAssignmentStatus::Completed)
        ->and($destination->status)->toBe(CrewAssignmentStatus::Draft);
});

test('p5 redeploy rejects multiple open post signoff hotel stays', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    [$assignment, $hotel] = makeActiveP5AssignmentWithPostSignoffHotel($fixtures);

    CrewAccommodationStay::factory()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PostSignoff,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-12-01',
        'check_out_date' => null,
        'started_from_phase_id' => $assignment->current_phase_id,
    ]);

    expect(fn () => app(CrewMovementService::class)->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::Redeploy, [
        'occurred_at' => '2026-12-05 09:00:00',
        'starting_phase' => CrewPhaseCode::PreMobilisation->value,
        'source_check_out_date' => '2026-12-05',
    ], $fixtures['user']->id))->toThrow(CrewMovementException::class);

    expect($assignment->fresh()->status)->toBe(CrewAssignmentStatus::Active)
        ->and(CrewAssignment::query()->where('previous_assignment_id', $assignment->id)->exists())->toBeFalse();
});

test('p5 redeploy rejects source checkout after redeployment date with redeploy semantics', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    [$assignment, , $stay] = makeActiveP5AssignmentWithPostSignoffHotel($fixtures);
    $service = app(CrewMovementService::class);

    expect(fn () => $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::Redeploy, [
        'occurred_at' => '2026-12-05 09:00:00',
        'starting_phase' => CrewPhaseCode::PreMobilisation->value,
        'source_check_out_date' => '2026-12-06',
    ], $fixtures['user']->id))->toThrow(function (CrewMovementException $exception): void {
        expect($exception->getMessage())->toBe(
            'Hotel check-out cannot be after the redeployment date.',
        )->and($exception->errorCode)->toBe('check_out_after_redeploy');
    });

    $assignment->refresh()->load('currentPhase');
    $stay->refresh();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::DemobStandby)
        ->and($stay->check_out_date)->toBeNull()
        ->and(CrewAssignment::query()->where('previous_assignment_id', $assignment->id)->exists())->toBeFalse();
});

test('p5 redeploy to p2a rejects foreign destination hotel without mutating source', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    ['company' => $companyB] = makeCrewAssignmentFixtures();
    [$assignment, , $stay] = makeActiveP5AssignmentWithPostSignoffHotel($fixtures);
    $foreignHotel = Hotel::factory()->create(['company_id' => $companyB->id]);
    $localHotel = Hotel::factory()->create(['company_id' => $fixtures['company']->id]);
    $foreignRoomType = RoomType::factory()->create([
        'company_id' => $companyB->id,
        'hotel_id' => $foreignHotel->id,
    ]);
    $service = app(CrewMovementService::class);

    expect(fn () => $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::Redeploy, [
        'occurred_at' => '2026-12-05 09:00:00',
        'starting_phase' => CrewPhaseCode::JoinStandby->value,
        'source_check_out_date' => '2026-12-05',
        'accommodation_status' => CrewAccommodationStatus::Hotel->value,
        'hotel_id' => $foreignHotel->id,
        'check_in_date' => '2026-12-05',
    ], $fixtures['user']->id))->toThrow(CrewMovementException::class);

    expect(fn () => $service->perform($fixtures['company']->id, $assignment->fresh()->id, CrewMovementAction::Redeploy, [
        'occurred_at' => '2026-12-05 09:00:00',
        'starting_phase' => CrewPhaseCode::JoinStandby->value,
        'source_check_out_date' => '2026-12-05',
        'accommodation_status' => CrewAccommodationStatus::Hotel->value,
        'hotel_id' => $localHotel->id,
        'room_type_id' => $foreignRoomType->id,
        'check_in_date' => '2026-12-05',
    ], $fixtures['user']->id))->toThrow(CrewMovementException::class);

    $assignment->refresh()->load('currentPhase');
    $stay->refresh();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::DemobStandby)
        ->and($stay->check_out_date)->toBeNull()
        ->and(CrewAssignment::query()->where('previous_assignment_id', $assignment->id)->exists())->toBeFalse();
});

test('p5 redeploy to p2a rejects inactive destination masters without mutating source', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    [$assignment, , $stay] = makeActiveP5AssignmentWithPostSignoffHotel($fixtures);
    $inactiveHotel = Hotel::factory()->create([
        'company_id' => $fixtures['company']->id,
        'is_active' => false,
    ]);
    $validHotel = Hotel::factory()->create([
        'company_id' => $fixtures['company']->id,
        'is_active' => true,
    ]);
    $inactiveRoomType = RoomType::factory()->create([
        'company_id' => $fixtures['company']->id,
        'hotel_id' => $validHotel->id,
        'is_active' => false,
    ]);
    $service = app(CrewMovementService::class);
    $payloadBase = [
        'occurred_at' => '2026-12-05 09:00:00',
        'starting_phase' => CrewPhaseCode::JoinStandby->value,
        'source_check_out_date' => '2026-12-05',
        'accommodation_status' => CrewAccommodationStatus::Hotel->value,
        'check_in_date' => '2026-12-05',
    ];

    expect(fn () => $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::Redeploy, [
        ...$payloadBase,
        'hotel_id' => $inactiveHotel->id,
    ], $fixtures['user']->id))->toThrow(CrewMovementException::class);

    expect(fn () => $service->perform($fixtures['company']->id, $assignment->fresh()->id, CrewMovementAction::Redeploy, [
        ...$payloadBase,
        'hotel_id' => $validHotel->id,
        'room_type_id' => $inactiveRoomType->id,
    ], $fixtures['user']->id))->toThrow(CrewMovementException::class);

    $assignment->refresh()->load('currentPhase');
    $stay->refresh();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Active)
        ->and($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::DemobStandby)
        ->and($stay->check_out_date)->toBeNull()
        ->and(CrewAssignment::query()->where('previous_assignment_id', $assignment->id)->exists())->toBeFalse();
});

test('cancel from p2a closes open pre join hotel stay', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['crew_operations.assignments.cancel']);
    [$assignment, , $stay] = makeActiveP2AAssignmentWithPreJoinHotel($fixtures);

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::CancelAssignment->value,
            'occurred_at' => '2026-09-18 08:00:00',
            'check_out_date' => '2026-09-18',
            'reason' => 'Client cancelled mobilisation',
        ])
        ->assertRedirect();

    $assignment->refresh();
    $stay->refresh();

    expect($assignment->status)->toBe(CrewAssignmentStatus::Cancelled)
        ->and($stay->check_out_date?->toDateString())->toBe('2026-09-18');
});

test('cancel from p2b closes the same open pre join hotel stay', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['crew_operations.assignments.cancel']);
    [$assignment, , $stay] = makeActiveP2AAssignmentWithPreJoinHotel($fixtures);
    $service = app(CrewMovementService::class);

    $service->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::SendToTraining, [
        'occurred_at' => '2026-09-17 08:00:00',
        'provider' => 'Training Center',
        'course' => 'Safety',
    ], $fixtures['user']->id);

    $assignment->refresh()->load('currentPhase');

    expect($assignment->currentPhase?->phase_code)->toBe(CrewPhaseCode::Training);

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::CancelAssignment->value,
            'occurred_at' => '2026-09-18 08:00:00',
            'check_out_date' => '2026-09-18',
            'reason' => 'Training mobilisation cancelled',
        ])
        ->assertRedirect();

    expect($assignment->fresh()->status)->toBe(CrewAssignmentStatus::Cancelled)
        ->and($stay->fresh()->check_out_date?->toDateString())->toBe('2026-09-18');
});

test('cancel from p5 closes open post signoff hotel stay', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['crew_operations.assignments.cancel']);
    [$assignment, , $stay] = makeActiveP5AssignmentWithPostSignoffHotel($fixtures);

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::CancelAssignment->value,
            'occurred_at' => '2026-12-06 08:00:00',
            'check_out_date' => '2026-12-06',
            'reason' => 'Demobilisation cancelled',
        ])
        ->assertRedirect();

    expect($assignment->fresh()->status)->toBe(CrewAssignmentStatus::Cancelled)
        ->and($stay->fresh()->check_out_date?->toDateString())->toBe('2026-12-06');
});

test('invalid cancel checkout leaves assignment active and stay open', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['crew_operations.assignments.cancel']);
    [$assignment, , $stay] = makeActiveP2AAssignmentWithPreJoinHotel($fixtures);

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::CancelAssignment->value,
            'occurred_at' => '2026-09-18 08:00:00',
            'check_out_date' => '2026-09-15',
            'reason' => 'Client cancelled mobilisation',
        ])
        ->assertSessionHasErrors('check_out_date');

    expect($assignment->fresh()->status)->toBe(CrewAssignmentStatus::Active)
        ->and($stay->fresh()->check_out_date)->toBeNull();
});

test('cancel without accommodation data remains allowed', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['crew_operations.assignments.cancel']);
    $assignment = startActivePreMobilisationAssignment($fixtures);

    app(CrewMovementService::class)->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-16 10:30:00',
    ], $fixtures['user']->id);

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment->fresh()), [
            'action' => CrewMovementAction::CancelAssignment->value,
            'occurred_at' => '2026-09-18 08:00:00',
            'reason' => 'No accommodation on record',
        ])
        ->assertRedirect();

    expect($assignment->fresh()->status)->toBe(CrewAssignmentStatus::Cancelled);
});

test('cancel with explicit no accommodation decision remains allowed', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['crew_operations.assignments.cancel']);
    $assignment = startActivePreMobilisationAssignment($fixtures);

    app(CrewMovementService::class)->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-16 10:30:00',
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation->value,
    ], $fixtures['user']->id);

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment->fresh()), [
            'action' => CrewMovementAction::CancelAssignment->value,
            'occurred_at' => '2026-09-18 08:00:00',
            'reason' => 'No hotel path',
        ])
        ->assertRedirect();

    expect($assignment->fresh()->status)->toBe(CrewAssignmentStatus::Cancelled);
});

test('cancel rejects multiple open hotel stays without partial mutation', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['crew_operations.assignments.cancel']);
    [$assignment, $hotel] = makeActiveP2AAssignmentWithPreJoinHotel($fixtures);

    CrewAccommodationStay::factory()->create([
        'company_id' => $fixtures['company']->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-10',
        'check_out_date' => null,
        'started_from_phase_id' => $assignment->current_phase_id,
    ]);

    $this->actingAs($fixtures['user'])
        ->post(route('organization.crew-assignments.perform-action', $assignment), [
            'action' => CrewMovementAction::CancelAssignment->value,
            'occurred_at' => '2026-09-18 08:00:00',
            'check_out_date' => '2026-09-18',
            'reason' => 'Should be blocked',
        ])
        ->assertSessionHasErrors('check_out_date');

    expect($assignment->fresh()->status)->toBe(CrewAssignmentStatus::Active);
});

test('void is blocked when pre join hotel stay exists', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['crew_operations.assignments.void']);
    [$assignment, , $stay] = makeActiveP2AAssignmentWithPreJoinHotel($fixtures);

    expect(fn () => app(VoidCrewAssignment::class)->handle(
        $fixtures['company']->id,
        $assignment->id,
        $fixtures['user'],
        'Should be blocked',
    ))->toThrow(ValidationException::class);

    expect($assignment->fresh()->trashed())->toBeFalse()
        ->and($stay->fresh()->check_out_date)->toBeNull()
        ->and(collect(app(CrewAssignmentVoidGuard::class)->blockers($assignment->fresh(), $fixtures['company']->id))->pluck('code'))
        ->toContain('accommodation_history_exists');
});

test('void is blocked when post signoff hotel stay exists', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['crew_operations.assignments.void']);
    [$assignment, , $stay] = makeActiveP5AssignmentWithPostSignoffHotel($fixtures);

    expect(fn () => app(VoidCrewAssignment::class)->handle(
        $fixtures['company']->id,
        $assignment->id,
        $fixtures['user'],
        'Should be blocked',
    ))->toThrow(ValidationException::class);

    expect($assignment->fresh()->trashed())->toBeFalse()
        ->and($stay->fresh()->check_out_date)->toBeNull();
});

test('void is blocked when no accommodation record exists', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['crew_operations.assignments.void']);
    $assignment = startActivePreMobilisationAssignment($fixtures);

    app(CrewMovementService::class)->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::RecordArrival, [
        'occurred_at' => '2026-09-16 10:30:00',
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation->value,
    ], $fixtures['user']->id);

    expect(fn () => app(VoidCrewAssignment::class)->handle(
        $fixtures['company']->id,
        $assignment->fresh()->id,
        $fixtures['user'],
        'Should be blocked',
    ))->toThrow(ValidationException::class);
});

test('void without accommodation history remains allowed', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['crew_operations.assignments.void']);
    $assignment = app(CrewMovementService::class)->createDraft($fixtures['company']->id, $fixtures['employee']->id, [
        'rank_id' => $fixtures['rank']->id,
    ], $fixtures['user']->id);

    app(VoidCrewAssignment::class)->handle(
        $fixtures['company']->id,
        $assignment->id,
        $fixtures['user'],
        'Entered by mistake',
    );

    expect(CrewAssignment::withTrashed()->findOrFail($assignment->id)->trashed())->toBeTrue();
});

test('current crew bulk loads p5 accommodation without per assignment presenter queries', function () {
    $fixtures = makeCrewMovementAccommodationFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], ['crew_operations.assignments.view']);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    foreach (['Hotel A', 'Hotel B', 'Hotel C'] as $index => $hotelName) {
        $employee = $index === 0
            ? $fixtures['employee']
            : Employee::factory()->forCompany($fixtures['company'])->create([
                'rank_id' => $fixtures['rank']->id,
                'status' => 'active',
            ]);

        $assignment = makeActiveOnVesselAssignment(
            $fixtures['company'],
            $employee,
            $fixtures['rank'],
            makeCrewMovementVessel("P5 Accommodation {$index}", $fixtures['company']),
        );

        $hotel = Hotel::factory()->create(['company_id' => $fixtures['company']->id, 'name' => $hotelName]);

        app(CrewMovementService::class)->perform($fixtures['company']->id, $assignment->id, CrewMovementAction::ConfirmDisembarkation, [
            'occurred_at' => '2026-11-30 09:30:00',
            'next_phase' => CrewPhaseCode::DemobStandby->value,
            'accommodation_status' => $index === 2 ? null : CrewAccommodationStatus::Hotel->value,
            'hotel_id' => $index === 2 ? null : $hotel->id,
            'check_in_date' => $index === 2 ? null : '2026-11-30',
        ], $fixtures['user']->id);
    }

    $page = CurrentCrewQuery::paginate(
        $fixtures['company']->id,
        [],
        CurrentCrewRequestFilters::VIEW_POST_SIGNOFF_HOTEL,
    );

    $items = collect($page->items())->map(function (CrewAssignment $assignment) {
        return CrewAssignmentPresenter::listItem($assignment);
    });

    DB::flushQueryLog();
    DB::enableQueryLog();
    foreach ($page->items() as $assignment) {
        CrewAssignmentPresenter::listItem($assignment);
    }
    $presenterQueries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($page->total())->toBe(3)
        ->and(
            $items
                ->pluck('movement_context.post_signoff_accommodation.status')
                ->countBy()
                ->all(),
        )
        ->toEqual([
            'open_hotel' => 2,
            'missing' => 1,
        ])
        ->and($presenterQueries)->toBeLessThanOrEqual(2);
});
