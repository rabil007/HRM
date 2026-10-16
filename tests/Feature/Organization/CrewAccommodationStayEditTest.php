<?php

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
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

beforeEach(function (): void {
    freezeCrewMovementTestClock();
});

afterEach(function (): void {
    restoreCrewMovementTestClock();
});

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

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function accommodationEditPayload(CrewAccommodationStay $stay, array $payload): array
{
    return array_merge([
        'expected_edit_version' => (int) $stay->edit_version,
    ], $payload);
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
            'expected_edit_version' => (int) $f['stay']->edit_version,
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
            'expected_edit_version' => (int) $f['stay']->edit_version,
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
                'expected_edit_version' => (int) $f['stay']->edit_version,
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
            'expected_edit_version' => (int) $otherStay->edit_version,
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
            'expected_edit_version' => (int) $otherStay->edit_version,
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
            'expected_edit_version' => (int) $stay->edit_version,
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
            'expected_edit_version' => (int) $f['stay']->edit_version,
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
            'expected_edit_version' => (int) $f['stay']->edit_version,
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
            'expected_edit_version' => (int) $f['stay']->edit_version,
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
            'expected_edit_version' => (int) $f['stay']->edit_version,
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
            'expected_edit_version' => (int) $f['stay']->edit_version,
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
            'expected_edit_version' => (int) $f['stay']->edit_version,
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
            'expected_edit_version' => (int) $f['stay']->edit_version,
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
            'expected_edit_version' => (int) $f['stay']->edit_version,
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
            'expected_edit_version' => (int) $f['stay']->edit_version,
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
            'expected_edit_version' => (int) $postStay->edit_version,
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
            'expected_edit_version' => (int) $postStay->edit_version,
        ])
        ->assertSessionHasErrors('check_out_date');

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->put(accommodationEditUrl($f['assignment'], $postStay), [
            'hotel_id' => $f['hotel']->id,
            'check_in_date' => '2026-10-15',
            'check_out_date' => '2026-10-16',
            'reason' => 'Valid demob stay',
            'expected_edit_version' => (int) $postStay->edit_version,
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
            'expected_edit_version' => (int) $f['stay']->edit_version,
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
            'expected_edit_version' => (int) $f['stay']->edit_version,
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
            'expected_edit_version' => (int) $f['stay']->edit_version,
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
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), accommodationEditPayload($f['stay'], [
            'hotel_id' => $f['hotel']->id,
            'room_type_id' => $f['roomType']->id,
            'check_in_date' => '2026-10-03',
            'check_out_date' => '2026-10-07',
            'reason' => 'Stale payload',
            'expected_edit_version' => (int) $f['stay']->edit_version + 5,
        ]))
        ->assertSessionHasErrors('expected_edit_version');
});

test('missing edit version is rejected', function () {
    $f = makeAccommodationEditFixtures();

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->from(route('organization.crew-assignments.show', $f['assignment']))
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), [
            'hotel_id' => $f['hotel']->id,
            'room_type_id' => $f['roomType']->id,
            'check_in_date' => '2026-10-03',
            'check_out_date' => '2026-10-07',
            'reason' => 'Missing version',
        ])
        ->assertSessionHasErrors('expected_edit_version');
});

test('competing updates within the same second reject the stale version', function () {
    $f = makeAccommodationEditFixtures();
    $version = (int) $f['stay']->edit_version;

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), accommodationEditPayload($f['stay'], [
            'hotel_id' => $f['hotel']->id,
            'room_type_id' => $f['roomType']->id,
            'check_in_date' => '2026-10-03',
            'check_out_date' => '2026-10-07',
            'reason' => 'First writer',
            'expected_edit_version' => $version,
        ]))
        ->assertRedirect();

    expect((int) $f['stay']->fresh()->edit_version)->toBe($version + 1);

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->from(route('organization.crew-assignments.show', $f['assignment']))
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), [
            'hotel_id' => $f['hotel']->id,
            'room_type_id' => $f['roomType']->id,
            'check_in_date' => '2026-10-04',
            'check_out_date' => '2026-10-07',
            'reason' => 'Second writer same second',
            'expected_edit_version' => $version,
        ])
        ->assertSessionHasErrors('expected_edit_version');

    expect($f['stay']->fresh()->check_in_date?->toDateString())->toBe('2026-10-03');
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
            'expected_edit_version' => (int) $f['stay']->edit_version,
        ])
        ->assertRedirect();

    $summary = app(CrewAccommodationService::class)->assignmentAccommodationSummary($f['assignment']->fresh());
    $item = collect($summary)->firstWhere('id', $f['stay']->id);

    expect($item['stay_days'])->toBe(5)
        ->and($item['edit_version'])->toBe((int) $f['stay']->fresh()->edit_version);
});

test('join vessel rejects closed pre-join stay that extends beyond the actual join date', function () {
    $f = makeAccommodationEditFixtures(withJoin: false);

    // Keep the crew on Pre-Joining Standby with an already-closed hotel stay that
    // extends beyond the join date that is about to execute.
    $f['onVessel']->delete();
    $f['joinStandby']->update([
        'status' => CrewPhaseStatus::Active,
        'actual_end_at' => null,
    ]);
    $f['assignment']->update(['current_phase_id' => $f['joinStandby']->id]);
    $f['stay']->update([
        'check_in_date' => '2026-10-03',
        'check_out_date' => '2026-10-10',
    ]);

    grantCompanyPermissions($f['user'], $f['company'], [
        'crew_operations.assignments.view',
        'crew_operations.corrections.override',
        'crew_operations.movements.perform',
        'audit.view',
    ]);

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->from(route('organization.crew-assignments.show', $f['assignment']))
        ->post(route('organization.crew-assignments.perform-action', $f['assignment']), [
            'action' => CrewMovementAction::JoinVessel->value,
            'occurred_at' => '2026-10-07 10:00:00',
            'vessel_id' => $f['assignment']->vessel_id,
            'position_id' => $f['assignment']->position_id,
            'check_out_date' => '2026-10-07',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('error');

    $f['assignment']->refresh()->load('currentPhase');
    expect($f['assignment']->currentPhase?->phase_code)->toBe(CrewPhaseCode::JoinStandby)
        ->and($f['stay']->fresh()->check_out_date?->toDateString())->toBe('2026-10-10');
});

test('travel home rejects closed demobilisation stay that extends beyond the actual home date', function () {
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
        'status' => CrewPhaseStatus::Active,
        'actual_start_at' => '2026-10-15 12:00:00',
    ]);
    $f['assignment']->update(['current_phase_id' => $demob->id]);

    $postStay = CrewAccommodationStay::factory()->create([
        'company_id' => $f['company']->id,
        'crew_assignment_id' => $f['assignment']->id,
        'hotel_id' => $f['hotel']->id,
        'stay_type' => CrewAccommodationStayType::PostSignoff,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-10-15',
        'check_out_date' => '2026-10-20',
        'started_from_phase_id' => $demob->id,
    ]);

    grantCompanyPermissions($f['user'], $f['company'], [
        'crew_operations.assignments.view',
        'crew_operations.corrections.override',
        'crew_operations.movements.perform',
        'audit.view',
    ]);

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->from(route('organization.crew-assignments.show', $f['assignment']))
        ->post(route('organization.crew-assignments.perform-action', $f['assignment']), [
            'action' => CrewMovementAction::TravelHome->value,
            'occurred_at' => '2026-10-16 18:00:00',
            'check_out_date' => '2026-10-16',
            'completion_intent' => 'close',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('error');

    expect($postStay->fresh()->check_out_date?->toDateString())->toBe('2026-10-20')
        ->and($f['assignment']->fresh()->currentPhase?->phase_code)->toBe(CrewPhaseCode::DemobStandby);
});

test('pre-arrival p0 activation is not treated as actual arrival for check-in bounds', function () {
    $f = makeAccommodationEditFixtures(withJoin: false);

    $preArrival = CrewAssignmentPhase::query()->create([
        'company_id' => $f['company']->id,
        'crew_assignment_id' => $f['assignment']->id,
        'phase_code' => CrewPhaseCode::PreArrival,
        'sequence' => 0,
        'status' => CrewPhaseStatus::Completed,
        'actual_start_at' => '2026-10-01 08:00:00',
        'actual_end_at' => '2026-10-03 08:00:00',
    ]);

    // No P1A arrival yet: P0 must not invent an arrival lower bound.
    $f['joinStandby']->update(['actual_start_at' => null, 'actual_end_at' => null]);
    $f['stay']->update([
        'started_from_phase_id' => $preArrival->id,
        'check_in_date' => '2026-09-28',
        'check_out_date' => null,
    ]);

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->put(accommodationEditUrl($f['assignment'], $f['stay']->fresh()), accommodationEditPayload($f['stay']->fresh(), [
            'hotel_id' => $f['hotel']->id,
            'room_type_id' => $f['roomType']->id,
            'check_in_date' => '2026-09-28',
            'check_out_date' => null,
            'reason' => 'Historical stay before P0 without P1A arrival',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    // Once authoritative P1A arrival exists, it becomes the lower bound (not P0).
    $f['joinStandby']->update(['actual_start_at' => '2026-10-03 08:00:00']);

    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->from(route('organization.crew-assignments.show', $f['assignment']))
        ->put(accommodationEditUrl($f['assignment'], $f['stay']->fresh()), accommodationEditPayload($f['stay']->fresh(), [
            'hotel_id' => $f['hotel']->id,
            'room_type_id' => $f['roomType']->id,
            'check_in_date' => '2026-10-02',
            'check_out_date' => null,
            'reason' => 'Before P1A arrival',
        ]))
        ->assertSessionHasErrors('check_in_date');
});

test('same-day hotel transfer boundary is allowed while interior overlaps are rejected', function () {
    $f = makeAccommodationEditFixtures(withJoin: false);

    $transferStay = CrewAccommodationStay::factory()->create([
        'company_id' => $f['company']->id,
        'crew_assignment_id' => $f['assignment']->id,
        'hotel_id' => $f['hotel']->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-10-05',
        'check_out_date' => '2026-10-06',
        'started_from_phase_id' => $f['joinStandby']->id,
    ]);

    // Shared boundary day (checkout 05, next check-in 05) is a legitimate transfer.
    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->put(accommodationEditUrl($f['assignment'], $f['stay']), accommodationEditPayload($f['stay'], [
            'hotel_id' => $f['hotel']->id,
            'room_type_id' => $f['roomType']->id,
            'check_in_date' => '2026-10-03',
            'check_out_date' => '2026-10-05',
            'reason' => 'Same-day hotel transfer boundary',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($f['stay']->fresh()->check_out_date?->toDateString())->toBe('2026-10-05')
        ->and($transferStay->fresh()->check_in_date?->toDateString())->toBe('2026-10-05');

    // Interior overlap remains invalid.
    $this->actingAs($f['user'])
        ->withSession(['current_company_id' => $f['company']->id])
        ->from(route('organization.crew-assignments.show', $f['assignment']))
        ->put(accommodationEditUrl($f['assignment'], $f['stay']->fresh()), accommodationEditPayload($f['stay']->fresh(), [
            'hotel_id' => $f['hotel']->id,
            'room_type_id' => $f['roomType']->id,
            'check_in_date' => '2026-10-03',
            'check_out_date' => '2026-10-06',
            'reason' => 'Interior overlap',
        ]))
        ->assertSessionHasErrors('check_in_date');
});

test('summary preserves inactive and unresolved hotel and room type selections for edit', function () {
    $f = makeAccommodationEditFixtures();
    $f['hotel']->update(['is_active' => false]);
    $f['roomType']->update(['is_active' => false]);

    $summary = app(CrewAccommodationService::class)->assignmentAccommodationSummary($f['assignment']->fresh());
    $item = collect($summary)->firstWhere('id', $f['stay']->id);

    expect($item['hotel_id'])->toBe($f['hotel']->id)
        ->and($item['hotel_is_active'])->toBeFalse()
        ->and($item['hotel_display'])->toContain('(inactive)')
        ->and($item['room_type_id'])->toBe($f['roomType']->id)
        ->and($item['room_type_is_active'])->toBeFalse()
        ->and($item['room_type_display'])->toContain('(inactive)')
        ->and($item['room_type_display'])->not->toBe('Not specified');
});
