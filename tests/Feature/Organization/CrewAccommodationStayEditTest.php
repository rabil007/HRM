<?php

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewPhaseCode;
use App\Enums\CrewPhaseStatus;
use App\Models\Company;
use App\Models\CrewAccommodationStay;
use App\Models\CrewAssignment;
use App\Models\CrewAssignmentPhase;
use App\Models\CrewMovementCorrection;
use App\Models\Hotel;
use App\Models\Position;
use App\Models\RoomType;
use App\Models\User;
use App\Support\CrewAccommodation\CrewAccommodationService;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/../../Support/privileged-two-factor.php';

/**
 * @return array{
 *     user: User,
 *     company: Company,
 *     assignment: CrewAssignment,
 *     joinStandby: CrewAssignmentPhase,
 *     onVessel: CrewAssignmentPhase,
 *     hotel: Hotel,
 *     roomType: RoomType,
 *     stay: CrewAccommodationStay
 * }
 */
function makeAccommodationEditFixtures(bool $withJoin = true): array
{
    $fixtures = makeCrewAssignmentFixtures();
    $user = $fixtures['user'];
    $company = $fixtures['company'];

    $user->update([
        'current_company_id' => $company->id,
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.corrections.override',
        'audit.view',
    ]);

    $vessel = makeCrewMovementVessel('Accommodation Edit Vessel', $company);
    $timezone = $company->timezone ?: 'UTC';

    $assignment = CrewAssignment::query()->create([
        'company_id' => $company->id,
        'assignment_no' => 'CA-ACC-EDIT-'.uniqid(),
        'employee_id' => $fixtures['employee']->id,
        'position_id' => $fixtures['rank']->id,
        'vessel_id' => $vessel->id,
        'status' => CrewAssignmentStatus::Active,
        'started_at' => '2026-10-03 08:00:00',
        'source' => 'manual',
    ]);

    $joinStandby = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::JoinStandby,
        'sequence' => 1,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-03 08:00:00',
        'actual_end_at' => '2026-10-07 10:00:00',
    ]);

    $onVessel = CrewAssignmentPhase::query()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'phase_code' => CrewPhaseCode::OnVessel,
        'sequence' => 2,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => $withJoin ? '2026-10-07 10:00:00' : null,
    ]);

    $assignment->update(['current_phase_id' => $onVessel->id]);

    $hotel = Hotel::factory()->create([
        'company_id' => $company->id,
        'name' => 'Hotel 1',
        'is_active' => true,
    ]);
    $roomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Single Room',
        'is_active' => true,
    ]);

    $stay = CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'room_type_id' => $roomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-10-03',
        'check_out_date' => $withJoin ? '2026-10-07' : null,
        'started_from_phase_id' => $joinStandby->id,
    ]);

    return [
        'user' => $user,
        'company' => $company,
        'assignment' => $assignment->fresh(['phases', 'employee']),
        'joinStandby' => $joinStandby,
        'onVessel' => $onVessel,
        'hotel' => $hotel,
        'roomType' => $roomType,
        'stay' => $stay->fresh(),
        'timezone' => $timezone,
    ];
}

function accommodationEditUrl(CrewAssignment $assignment, CrewAccommodationStay $stay): string
{
    return route('organization.crew-assignments.accommodation-stays.update', [
        'assignment' => $assignment,
        'stay' => $stay,
    ]);
}

test('override permission with privileged 2fa can edit accommodation stay', function () {
    $f = makeAccommodationEditFixtures();
    $otherHotel = Hotel::factory()->create([
        'company_id' => $f['company']->id,
        'name' => 'Hotel 2',
        'is_active' => true,
    ]);
    $otherRoom = RoomType::factory()->create([
        'company_id' => $f['company']->id,
        'hotel_id' => $otherHotel->id,
        'name' => 'Twin',
        'is_active' => true,
    ]);

    $phaseSnapshot = $f['assignment']->phases->map->only(['id', 'phase_code', 'actual_start_at', 'actual_end_at', 'status'])->all();

    $response = $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->from(route('organization.crew-assignments.show', $f['assignment']))
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), [
            'hotel_id' => $otherHotel->id,
            'room_type_id' => $otherRoom->id,
            'check_in_date' => '2026-10-04',
            'check_out_date' => '2026-10-07',
            'reason' => 'Corrected hotel booking reference',
            'expected_updated_at' => $f['stay']->updated_at?->toISOString(),
        ]);

    $response->assertRedirect(route('organization.crew-assignments.show', $f['assignment']));
    $response->assertSessionHas('success');

    $f['stay']->refresh();
    expect($f['stay']->hotel_id)->toBe($otherHotel->id)
        ->and($f['stay']->room_type_id)->toBe($otherRoom->id)
        ->and($f['stay']->check_in_date?->toDateString())->toBe('2026-10-04')
        ->and($f['stay']->check_out_date?->toDateString())->toBe('2026-10-07');

    $f['assignment']->refresh()->load('phases');
    expect($f['assignment']->phases->map->only(['id', 'phase_code', 'actual_start_at', 'actual_end_at', 'status'])->all())
        ->toEqual($phaseSnapshot);

    expect(CrewMovementCorrection::query()->where('crew_assignment_id', $f['assignment']->id)->count())->toBe(0);

    $activity = Activity::query()
        ->where('company_id', $f['company']->id)
        ->where('subject_type', CrewAssignment::class)
        ->where('subject_id', $f['assignment']->id)
        ->where('description', 'Crew accommodation stay edited')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['reason'] ?? null)->toBe('Corrected hotel booking reference')
        ->and($activity->properties['previous_values']['hotel_id'] ?? null)->toBe($f['hotel']->id)
        ->and($activity->properties['updated_values']['hotel_id'] ?? null)->toBe($otherHotel->id);
});

test('user without corrections.override cannot edit accommodation stay', function () {
    $f = makeAccommodationEditFixtures();
    $viewer = User::factory()->create();
    $viewer->update(['current_company_id' => $f['company']->id]);
    grantCompanyPermissions($viewer, $f['company'], [
        'crew_operations.assignments.view',
    ]);

    $this->actingAs($viewer)
        ->withSession(['current_company_id' => $f['company']->id])
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), [
            'hotel_id' => $f['hotel']->id,
            'room_type_id' => $f['roomType']->id,
            'check_in_date' => '2026-10-03',
            'check_out_date' => '2026-10-07',
            'reason' => 'Unauthorized attempt',
        ])
        ->assertForbidden();

    expect($f['stay']->fresh()->check_in_date?->toDateString())->toBe('2026-10-03');
});

test('privileged 2fa is enforced for accommodation stay edits', function () {
    enablePrivilegedTwoFactorEnforcement();

    try {
        $f = makeAccommodationEditFixtures();
        $f['user']->update([
            'two_factor_confirmed_at' => null,
        ]);
        $originalCheckIn = $f['stay']->check_in_date?->toDateString();

        $this->actingAs($f['user'])
            ->withSession(['current_company_id' => $f['company']->id])
            ->put(accommodationEditUrl($f['assignment'], $f['stay']), [
                'hotel_id' => $f['hotel']->id,
                'room_type_id' => $f['roomType']->id,
                'check_in_date' => '2026-10-04',
                'check_out_date' => '2026-10-07',
                'reason' => 'Needs 2FA',
            ])
            ->assertRedirect(route('security.edit'));

        expect($f['stay']->fresh()->check_in_date?->toDateString())->toBe($originalCheckIn);
    } finally {
        disablePrivilegedTwoFactorEnforcement();
    }
});

test('cross-company accommodation stay ids are rejected', function () {
    $f = makeAccommodationEditFixtures();
    $other = makeCrewAssignmentFixtures();
    $otherVessel = makeCrewMovementVessel('Other Co Vessel', $other['company']);
    $otherAssignment = makeCurrentCrewPhaseAssignment(
        $other['company'],
        $other['employee'],
        $other['rank'],
        $otherVessel,
        CrewPhaseCode::JoinStandby,
    );
    $otherHotel = Hotel::factory()->create(['company_id' => $other['company']->id, 'is_active' => true]);
    $otherStay = CrewAccommodationStay::factory()->create([
        'company_id' => $other['company']->id,
        'crew_assignment_id' => $otherAssignment->id,
        'hotel_id' => $otherHotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-10-03',
        'check_out_date' => '2026-10-07',
    ]);

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->put(accommodationEditUrl($f['assignment'], $otherStay), [
            'hotel_id' => $f['hotel']->id,
            'check_in_date' => '2026-10-03',
            'check_out_date' => '2026-10-07',
            'reason' => 'Cross company',
        ])
        ->assertNotFound();
});

test('stay belonging to another assignment is rejected', function () {
    $f = makeAccommodationEditFixtures();
    $vessel = makeCrewMovementVessel('Sibling Vessel', $f['company']);
    $otherAssignment = makeCurrentCrewPhaseAssignment(
        $f['company'],
        $f['assignment']->employee,
        Position::query()->findOrFail($f['assignment']->position_id),
        $vessel,
        CrewPhaseCode::JoinStandby,
    );
    $otherStay = CrewAccommodationStay::factory()->create([
        'company_id' => $f['company']->id,
        'crew_assignment_id' => $otherAssignment->id,
        'hotel_id' => $f['hotel']->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-10-03',
        'check_out_date' => '2026-10-05',
    ]);

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->put(accommodationEditUrl($f['assignment'], $otherStay), [
            'hotel_id' => $f['hotel']->id,
            'check_in_date' => '2026-10-03',
            'check_out_date' => '2026-10-05',
            'reason' => 'Wrong assignment',
        ])
        ->assertNotFound();
});

test('employee visibility scope blocks editing hidden office employee accommodation', function () {
    $fixtures = makeEmployeeVisibilityFixtures();
    $user = $fixtures['user'];
    $company = $fixtures['company'];
    $user->update([
        'current_company_id' => $company->id,
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ]);
    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.corrections.override',
    ]);
    restrictUserToDepartments($user, $company, [$fixtures['marineDept']->id]);

    $rank = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Visibility Rank '.uniqid(),
        'status' => 'active',
        'is_crew_position' => true,
    ]);
    $vessel = makeCrewMovementVessel('Visibility Acc Vessel', $company);
    $officeAssignment = makeActiveOnVesselAssignment($company, $fixtures['officeEmployee'], $rank, $vessel);
    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'is_active' => true]);
    $stay = CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $officeAssignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-01-01',
        'check_out_date' => '2026-01-03',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put(accommodationEditUrl($officeAssignment, $stay), [
            'hotel_id' => $hotel->id,
            'check_in_date' => '2026-01-01',
            'check_out_date' => '2026-01-03',
            'reason' => 'Hidden employee',
        ])
        ->assertNotFound();
});

test('optional room type can be cleared and missing room type displays not specified', function () {
    $f = makeAccommodationEditFixtures();

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), [
            'hotel_id' => $f['hotel']->id,
            'room_type_id' => null,
            'check_in_date' => '2026-10-03',
            'check_out_date' => '2026-10-07',
            'reason' => 'Room type was never confirmed',
        ])
        ->assertRedirect();

    expect($f['stay']->fresh()->room_type_id)->toBeNull();

    $summary = app(CrewAccommodationService::class)->assignmentAccommodationSummary($f['assignment']->fresh());
    $item = collect($summary)->firstWhere('id', $f['stay']->id);

    expect($item['room_type_id'])->toBeNull()
        ->and($item['room_type_name'])->toBeNull()
        ->and($item['room_type_display'])->toBe('Not specified')
        ->and($item['hotel_id'])->toBe($f['hotel']->id);
});

test('unresolved historical room type reference is not treated as null', function () {
    $f = makeAccommodationEditFixtures();
    $other = makeCrewAssignmentFixtures();
    $foreignRoomType = RoomType::factory()->create([
        'company_id' => $other['company']->id,
        'hotel_id' => null,
        'name' => 'Foreign Room',
        'is_active' => true,
    ]);

    DB::table('crew_accommodation_stays')
        ->where('id', $f['stay']->id)
        ->update(['room_type_id' => $foreignRoomType->id]);

    $assignment = $f['assignment']->fresh(['accommodationStays.hotel', 'accommodationStays.roomType']);
    $summary = app(CrewAccommodationService::class)->assignmentAccommodationSummary($assignment);
    $item = collect($summary)->firstWhere('id', $f['stay']->id);

    expect($item['room_type_id'])->toBe($foreignRoomType->id)
        ->and($item['room_type_name'])->toBeNull()
        ->and($item['room_type_display'])->toBe('Unavailable');
});

test('wrong-hotel room type is rejected', function () {
    $f = makeAccommodationEditFixtures();
    $otherHotel = Hotel::factory()->create(['company_id' => $f['company']->id, 'is_active' => true]);
    $wrongRoom = RoomType::factory()->create([
        'company_id' => $f['company']->id,
        'hotel_id' => $otherHotel->id,
        'is_active' => true,
    ]);

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->from(route('organization.crew-assignments.show', $f['assignment']))
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), [
            'hotel_id' => $f['hotel']->id,
            'room_type_id' => $wrongRoom->id,
            'check_in_date' => '2026-10-03',
            'check_out_date' => '2026-10-07',
            'reason' => 'Wrong hotel room',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('room_type_id');

    expect($f['stay']->fresh()->room_type_id)->toBe($f['roomType']->id);
});

test('inactive newly selected hotel and room type are rejected', function () {
    $f = makeAccommodationEditFixtures();
    $inactiveHotel = Hotel::factory()->create([
        'company_id' => $f['company']->id,
        'is_active' => false,
    ]);

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->from(route('organization.crew-assignments.show', $f['assignment']))
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), [
            'hotel_id' => $inactiveHotel->id,
            'check_in_date' => '2026-10-03',
            'check_out_date' => '2026-10-07',
            'reason' => 'Inactive hotel',
        ])
        ->assertSessionHasErrors('hotel_id');

    $inactiveRoom = RoomType::factory()->create([
        'company_id' => $f['company']->id,
        'hotel_id' => $f['hotel']->id,
        'is_active' => false,
    ]);

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->from(route('organization.crew-assignments.show', $f['assignment']))
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), [
            'hotel_id' => $f['hotel']->id,
            'room_type_id' => $inactiveRoom->id,
            'check_in_date' => '2026-10-03',
            'check_out_date' => '2026-10-07',
            'reason' => 'Inactive room',
        ])
        ->assertSessionHasErrors('room_type_id');
});

test('pre-join check-in before actual arrival is rejected', function () {
    $f = makeAccommodationEditFixtures();

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->from(route('organization.crew-assignments.show', $f['assignment']))
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), [
            'hotel_id' => $f['hotel']->id,
            'room_type_id' => $f['roomType']->id,
            'check_in_date' => '2026-10-02',
            'check_out_date' => '2026-10-07',
            'reason' => 'Too early',
        ])
        ->assertSessionHasErrors('check_in_date');
});

test('pre-join checkout after actual joining is rejected while same-day joining remains valid', function () {
    $f = makeAccommodationEditFixtures();

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->from(route('organization.crew-assignments.show', $f['assignment']))
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), [
            'hotel_id' => $f['hotel']->id,
            'room_type_id' => $f['roomType']->id,
            'check_in_date' => '2026-10-03',
            'check_out_date' => '2026-10-08',
            'reason' => 'After join',
        ])
        ->assertSessionHasErrors('check_out_date');

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), [
            'hotel_id' => $f['hotel']->id,
            'room_type_id' => $f['roomType']->id,
            'check_in_date' => '2026-10-03',
            'check_out_date' => '2026-10-07',
            'reason' => 'Same-day join checkout',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

test('open pre-join stay cannot be reopened after joining has occurred', function () {
    $f = makeAccommodationEditFixtures(withJoin: true);

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->from(route('organization.crew-assignments.show', $f['assignment']))
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), [
            'hotel_id' => $f['hotel']->id,
            'room_type_id' => $f['roomType']->id,
            'check_in_date' => '2026-10-03',
            'check_out_date' => null,
            'reason' => 'Reopen after join',
        ])
        ->assertSessionHasErrors('check_out_date');
});

test('valid open accommodation remains supported before joining', function () {
    $f = makeAccommodationEditFixtures(withJoin: false);

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), [
            'hotel_id' => $f['hotel']->id,
            'room_type_id' => $f['roomType']->id,
            'check_in_date' => '2026-10-04',
            'check_out_date' => null,
            'reason' => 'Still waiting to join',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($f['stay']->fresh()->check_out_date)->toBeNull()
        ->and($f['stay']->fresh()->check_in_date?->toDateString())->toBe('2026-10-04');
});

test('demobilisation stay validates disembarkation and home boundaries', function () {
    $f = makeAccommodationEditFixtures();
    $f['onVessel']->update([
        'status' => CrewPhaseStatus::Completed,
        'actual_end_at' => '2026-10-15 12:00:00',
    ]);
    $demob = CrewAssignmentPhase::query()->create([
        'company_id' => $f['company']->id,
        'crew_assignment_id' => $f['assignment']->id,
        'phase_code' => CrewPhaseCode::DemobStandby,
        'sequence' => 3,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-15 12:00:00',
        'actual_end_at' => '2026-10-16 18:00:00',
    ]);
    $home = CrewAssignmentPhase::query()->create([
        'company_id' => $f['company']->id,
        'crew_assignment_id' => $f['assignment']->id,
        'phase_code' => CrewPhaseCode::HomeRedeploy,
        'sequence' => 4,
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-10-16 18:00:00',
    ]);
    $f['assignment']->update(['current_phase_id' => $home->id]);

    $postStay = CrewAccommodationStay::factory()->create([
        'company_id' => $f['company']->id,
        'crew_assignment_id' => $f['assignment']->id,
        'hotel_id' => $f['hotel']->id,
        'room_type_id' => null,
        'stay_type' => CrewAccommodationStayType::PostSignoff,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-10-15',
        'check_out_date' => '2026-10-16',
        'started_from_phase_id' => $demob->id,
    ]);

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->from(route('organization.crew-assignments.show', $f['assignment']))
        ->put(accommodationEditUrl($f['assignment'], $postStay), [
            'hotel_id' => $f['hotel']->id,
            'check_in_date' => '2026-10-14',
            'check_out_date' => '2026-10-16',
            'reason' => 'Before disembarkation',
        ])
        ->assertSessionHasErrors('check_in_date');

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->from(route('organization.crew-assignments.show', $f['assignment']))
        ->put(accommodationEditUrl($f['assignment'], $postStay), [
            'hotel_id' => $f['hotel']->id,
            'check_in_date' => '2026-10-15',
            'check_out_date' => '2026-10-17',
            'reason' => 'After home',
        ])
        ->assertSessionHasErrors('check_out_date');

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->put(accommodationEditUrl($f['assignment'], $postStay), [
            'hotel_id' => $f['hotel']->id,
            'check_in_date' => '2026-10-15',
            'check_out_date' => '2026-10-16',
            'reason' => 'Valid demob stay',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

test('checkout before check-in and overlapping stays are rejected', function () {
    $f = makeAccommodationEditFixtures(withJoin: false);

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->from(route('organization.crew-assignments.show', $f['assignment']))
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), [
            'hotel_id' => $f['hotel']->id,
            'check_in_date' => '2026-10-05',
            'check_out_date' => '2026-10-04',
            'reason' => 'Reversed dates',
        ])
        ->assertSessionHasErrors('check_out_date');

    CrewAccommodationStay::factory()->create([
        'company_id' => $f['company']->id,
        'crew_assignment_id' => $f['assignment']->id,
        'hotel_id' => $f['hotel']->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-10-05',
        'check_out_date' => '2026-10-06',
        'started_from_phase_id' => $f['joinStandby']->id,
    ]);

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->from(route('organization.crew-assignments.show', $f['assignment']))
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), [
            'hotel_id' => $f['hotel']->id,
            'check_in_date' => '2026-10-03',
            'check_out_date' => '2026-10-06',
            'reason' => 'Overlap',
        ])
        ->assertSessionHasErrors('check_in_date');
});

test('edit reason is required and failed validation leaves records unchanged', function () {
    $f = makeAccommodationEditFixtures();
    $original = $f['stay']->only(['hotel_id', 'room_type_id', 'check_in_date', 'check_out_date']);

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->from(route('organization.crew-assignments.show', $f['assignment']))
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), [
            'hotel_id' => $f['hotel']->id,
            'room_type_id' => $f['roomType']->id,
            'check_in_date' => '2026-10-04',
            'check_out_date' => '2026-10-07',
            'reason' => '',
        ])
        ->assertSessionHasErrors('reason');

    $fresh = $f['stay']->fresh();
    expect($fresh->hotel_id)->toBe($original['hotel_id'])
        ->and($fresh->room_type_id)->toBe($original['room_type_id'])
        ->and($fresh->check_in_date?->toDateString())->toBe($original['check_in_date']->toDateString());
});

test('stale concurrent update is rejected', function () {
    $f = makeAccommodationEditFixtures();

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->from(route('organization.crew-assignments.show', $f['assignment']))
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), [
            'hotel_id' => $f['hotel']->id,
            'room_type_id' => $f['roomType']->id,
            'check_in_date' => '2026-10-03',
            'check_out_date' => '2026-10-07',
            'reason' => 'Stale payload',
            'expected_updated_at' => now()->subHour()->toISOString(),
        ])
        ->assertSessionHasErrors('expected_updated_at');
});

test('assignment show includes hotel and room type ids and edit permission flag', function () {
    $f = makeAccommodationEditFixtures();

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->get(route('organization.crew-assignments.show', $f['assignment']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/show')
            ->where('can.override_corrections', true)
            ->where('assignment.accommodation.0.hotel_id', $f['hotel']->id)
            ->where('assignment.accommodation.0.room_type_id', $f['roomType']->id)
            ->where('assignment.accommodation.0.room_type_display', 'Single Room')
            ->where('assignment.accommodation.0.stay_days', 5)
        );
});

test('inclusive hotel days remain five for third through seventh october stay', function () {
    $f = makeAccommodationEditFixtures();

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), [
            'hotel_id' => $f['hotel']->id,
            'room_type_id' => $f['roomType']->id,
            'check_in_date' => '2026-10-03',
            'check_out_date' => '2026-10-07',
            'reason' => 'Confirm inclusive days',
        ])
        ->assertRedirect();

    $summary = app(CrewAccommodationService::class)->assignmentAccommodationSummary($f['assignment']->fresh());
    $item = collect($summary)->firstWhere('id', $f['stay']->id);

    expect($item['stay_days'])->toBe(5);
});
