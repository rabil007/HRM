<?php

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
use App\Enums\CrewPhaseCode;
use App\Models\CrewAccommodationStay;
use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Validation\ValidationException;

test('crew accommodation stay belongs to assignment hotel and room type', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Accommodation Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Royal Rose']);
    $roomType = RoomType::factory()->create(['company_id' => $company->id, 'name' => 'Twin Sharing']);

    $stay = CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'room_type_id' => $roomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-16',
        'check_out_date' => '2026-09-19',
    ]);

    $stay->load(['assignment', 'hotel', 'roomType']);

    expect($stay->assignment->id)->toBe($assignment->id)
        ->and($stay->hotel->id)->toBe($hotel->id)
        ->and($stay->roomType->id)->toBe($roomType->id);
});

test('multiple accommodation stays can belong to one assignment including repeated stay types', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Multi Stay Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $first = CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation,
    ]);

    $second = CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation,
    ]);

    expect($assignment->accommodationStays()->count())->toBe(2)
        ->and($first->stay_type)->toBe(CrewAccommodationStayType::PreJoin)
        ->and($second->stay_type)->toBe(CrewAccommodationStayType::PreJoin);
});

test('crew accommodation stay stores stay types and accommodation statuses', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Enum Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::DemobStandby);
    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'City Seasons']);

    $preJoin = CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-16',
        'check_out_date' => null,
        'room_type_id' => null,
    ]);

    $postSignoff = CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'stay_type' => CrewAccommodationStayType::PostSignoff,
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation,
        'hotel_id' => null,
        'room_type_id' => null,
        'check_in_date' => null,
        'check_out_date' => null,
    ]);

    expect($preJoin->stay_type)->toBe(CrewAccommodationStayType::PreJoin)
        ->and($preJoin->accommodation_status)->toBe(CrewAccommodationStatus::Hotel)
        ->and($preJoin->check_out_date)->toBeNull()
        ->and($preJoin->room_type_id)->toBeNull()
        ->and($postSignoff->stay_type)->toBe(CrewAccommodationStayType::PostSignoff)
        ->and($postSignoff->accommodation_status)->toBe(CrewAccommodationStatus::NoAccommodation);
});

test('checkout before check in is rejected', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Date Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);
    $hotel = Hotel::factory()->create(['company_id' => $company->id]);

    CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-20',
        'check_out_date' => '2026-09-18',
    ]);
})->throws(ValidationException::class);

test('hotel status without hotel is rejected', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Missing Hotel Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => null,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-16',
    ]);
})->throws(ValidationException::class);

test('hotel status without check-in is rejected', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Missing Check-In Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);
    $hotel = Hotel::factory()->create(['company_id' => $company->id]);

    CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => null,
    ]);
})->throws(ValidationException::class);

test('no accommodation status rejects hotel', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('No Accommodation Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);
    $hotel = Hotel::factory()->create(['company_id' => $company->id]);

    CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation,
        'hotel_id' => $hotel->id,
        'room_type_id' => null,
        'check_in_date' => null,
        'check_out_date' => null,
    ]);
})->throws(ValidationException::class);

test('no accommodation status rejects room type', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('No Accommodation Room Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);
    $roomType = RoomType::factory()->create(['company_id' => $company->id]);

    CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation,
        'hotel_id' => null,
        'room_type_id' => $roomType->id,
        'check_in_date' => null,
        'check_out_date' => null,
    ]);
})->throws(ValidationException::class);

test('no accommodation status rejects check-in date', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('No Accommodation Check-In Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation,
        'hotel_id' => null,
        'room_type_id' => null,
        'check_in_date' => '2026-09-16',
        'check_out_date' => null,
    ]);
})->throws(ValidationException::class);

test('no accommodation status rejects check-out date', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('No Accommodation Check-Out Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation,
        'hotel_id' => null,
        'room_type_id' => null,
        'check_in_date' => null,
        'check_out_date' => '2026-09-18',
    ]);
})->throws(ValidationException::class);

test('historical accommodation survives hotel and room type deactivation', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Deactivate Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'is_active' => true]);
    $roomType = RoomType::factory()->create(['company_id' => $company->id, 'is_active' => true]);

    $stay = CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'room_type_id' => $roomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-16',
    ]);

    $hotel->update(['is_active' => false]);
    $roomType->update(['is_active' => false]);

    $stay->refresh()->load(['hotel', 'roomType']);

    expect($stay->hotel)->not->toBeNull()
        ->and($stay->roomType)->not->toBeNull();
});

test('tenant relationships cannot cross companies on create', function () {
    ['company' => $companyA, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    ['company' => $companyB] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Tenant Vessel', $companyA);
    $assignment = makeCurrentCrewPhaseAssignment($companyA, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);
    $foreignHotel = Hotel::factory()->create(['company_id' => $companyB->id]);

    CrewAccommodationStay::factory()->create([
        'company_id' => $companyA->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $foreignHotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-16',
    ]);
})->throws(ValidationException::class);

test('stale loaded hotel relation cannot bypass tenant integrity on update', function () {
    ['company' => $companyA, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    ['company' => $companyB] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Stale Hotel Vessel', $companyA);
    $assignment = makeCurrentCrewPhaseAssignment($companyA, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $companyAHotel = Hotel::factory()->create(['company_id' => $companyA->id, 'name' => 'Company A Hotel']);
    $foreignHotel = Hotel::factory()->create(['company_id' => $companyB->id, 'name' => 'Foreign Hotel']);

    $stay = CrewAccommodationStay::factory()->create([
        'company_id' => $companyA->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $companyAHotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-16',
    ]);

    $stay->load('hotel');
    $stay->hotel_id = $foreignHotel->id;

    expect(fn () => $stay->save())->toThrow(ValidationException::class);
    expect($stay->fresh()->hotel_id)->toBe($companyAHotel->id);
});

test('stale loaded room type relation cannot bypass tenant integrity on update', function () {
    ['company' => $companyA, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    ['company' => $companyB] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Stale Room Vessel', $companyA);
    $assignment = makeCurrentCrewPhaseAssignment($companyA, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);
    $hotel = Hotel::factory()->create(['company_id' => $companyA->id]);

    $companyARoomType = RoomType::factory()->create(['company_id' => $companyA->id, 'name' => 'Company A Room']);
    $foreignRoomType = RoomType::factory()->create(['company_id' => $companyB->id, 'name' => 'Foreign Room']);

    $stay = CrewAccommodationStay::factory()->create([
        'company_id' => $companyA->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'room_type_id' => $companyARoomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-16',
    ]);

    $stay->load('roomType');
    $stay->room_type_id = $foreignRoomType->id;

    expect(fn () => $stay->save())->toThrow(ValidationException::class);
    expect($stay->fresh()->room_type_id)->toBe($companyARoomType->id);
});

test('stale loaded assignment relation cannot bypass tenant integrity on update', function () {
    ['company' => $companyA, 'employee' => $employeeA, 'rank' => $rankA] = makeCrewAssignmentFixtures();
    ['company' => $companyB, 'employee' => $employeeB, 'rank' => $rankB] = makeCrewAssignmentFixtures();

    $vesselA = makeCrewMovementVessel('Assignment A Vessel', $companyA);
    $assignmentA = makeCurrentCrewPhaseAssignment($companyA, $employeeA, $rankA, $vesselA, CrewPhaseCode::JoinStandby);

    $vesselB = makeCrewMovementVessel('Assignment B Vessel', $companyB);
    $assignmentB = makeCurrentCrewPhaseAssignment($companyB, $employeeB, $rankB, $vesselB, CrewPhaseCode::JoinStandby);

    $stay = CrewAccommodationStay::factory()->create([
        'company_id' => $companyA->id,
        'crew_assignment_id' => $assignmentA->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation,
    ]);

    $stay->load('assignment');
    $stay->crew_assignment_id = $assignmentB->id;

    expect(fn () => $stay->save())->toThrow(ValidationException::class);
    expect($stay->fresh()->crew_assignment_id)->toBe($assignmentA->id);
});

test('stale loaded started from phase relation cannot bypass tenant integrity on update', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Stale Phase Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    ['employee' => $otherEmployee, 'rank' => $otherRank] = makeCrewAssignmentFixtures();
    $otherVessel = makeCrewMovementVessel('Other Phase Vessel', $company);
    $otherAssignment = makeCurrentCrewPhaseAssignment($company, $otherEmployee, $otherRank, $otherVessel, CrewPhaseCode::JoinStandby);

    $stay = CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'started_from_phase_id' => $assignment->current_phase_id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation,
    ]);

    $stay->load('startedFromPhase');
    $stay->started_from_phase_id = $otherAssignment->current_phase_id;

    expect(fn () => $stay->save())->toThrow(ValidationException::class);
    expect($stay->fresh()->started_from_phase_id)->toBe($assignment->current_phase_id);
});

test('started from phase must belong to the same assignment and company on create', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Phase Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    ['employee' => $otherEmployee, 'rank' => $otherRank] = makeCrewAssignmentFixtures();
    $otherVessel = makeCrewMovementVessel('Other Vessel', $company);
    $otherAssignment = makeCurrentCrewPhaseAssignment($company, $otherEmployee, $otherRank, $otherVessel, CrewPhaseCode::JoinStandby);

    CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'started_from_phase_id' => $otherAssignment->current_phase_id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::NoAccommodation,
    ]);
})->throws(ValidationException::class);
