<?php

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
use App\Enums\CrewPhaseCode;
use App\Models\Company;
use App\Models\Country;
use App\Models\CrewAccommodationStay;
use App\Models\Currency;
use App\Models\RoomType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot access room types page', function () {
    $this->get('/settings/master-data/room-types')->assertRedirect(route('login'));
});

test('authorized users can view, create, update, and delete room types', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'RMT',
        'name' => 'Room Type Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'RMT',
        'name' => 'Room Type Currency',
        'symbol' => 'R$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Room Type Co',
        'slug' => 'room-type-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.room-types.view',
        'settings.master-data.room-types.create',
        'settings.master-data.room-types.update',
        'settings.master-data.room-types.delete',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get('/settings/master-data/room-types')
        ->assertOk();

    $this->withSession(['current_company_id' => $company->id])
        ->post('/settings/master-data/room-types', [
            'name' => 'Twin Sharing',
            'description' => 'Two beds',
            'is_active' => true,
        ])->assertRedirect(route('settings.master-data.room-types.index'));

    $roomType = RoomType::query()->where('company_id', $company->id)->where('name', 'Twin Sharing')->first();
    expect($roomType)->not->toBeNull();

    $this->withSession(['current_company_id' => $company->id])
        ->put("/settings/master-data/room-types/{$roomType->id}", [
            'name' => 'Single Room',
            'description' => 'Updated description',
            'is_active' => false,
        ])->assertRedirect(route('settings.master-data.room-types.index'));

    $this->assertDatabaseHas('room_types', [
        'id' => $roomType->id,
        'company_id' => $company->id,
        'name' => 'Single Room',
        'description' => 'Updated description',
        'is_active' => 0,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->delete("/settings/master-data/room-types/{$roomType->id}")
        ->assertRedirect(route('settings.master-data.room-types.index'));

    $this->assertSoftDeleted('room_types', ['id' => $roomType->id]);
});

test('room types index supports search and pagination meta', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'RPG',
        'name' => 'Room Page Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'RPG',
        'name' => 'Room Page Currency',
        'symbol' => 'P$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Room Page Co',
        'slug' => 'room-page-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.room-types.view',
    ]);

    RoomType::factory()->create(['company_id' => $company->id, 'name' => 'Alpha Room', 'is_active' => true]);
    RoomType::factory()->create(['company_id' => $company->id, 'name' => 'Beta Room', 'is_active' => true]);
    RoomType::factory()->create(['company_id' => $company->id, 'name' => 'Other Label', 'is_active' => true]);

    $this->withSession(['current_company_id' => $company->id])
        ->get('/settings/master-data/room-types?search=Room&per_page=10')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/room-types')
            ->where('search', 'Room')
            ->where('pagination.per_page', 10)
            ->where('pagination.total', 2)
            ->has('room_types', 2)
        );
});

test('room type names are unique per company', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeCrewAssignmentFixtures();

    grantCompanyPermissions($user, $company, [
        'settings.master-data.room-types.create',
    ]);

    RoomType::factory()->create([
        'company_id' => $company->id,
        'name' => 'Twin Sharing',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post('/settings/master-data/room-types', [
            'name' => 'Twin Sharing',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('name');
});

test('users without room type permissions are forbidden', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeCrewAssignmentFixtures();

    $this->withSession(['current_company_id' => $company->id])
        ->get('/settings/master-data/room-types')
        ->assertForbidden();
});

test('company a cannot access update or delete company b room types', function () {
    ['user' => $user, 'company' => $companyA] = makeCrewAssignmentFixtures();
    ['company' => $companyB] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $companyA, [
        'settings.master-data.room-types.view',
        'settings.master-data.room-types.update',
        'settings.master-data.room-types.delete',
    ]);

    $foreignRoomType = RoomType::factory()->create([
        'company_id' => $companyB->id,
        'name' => 'Foreign Room',
    ]);

    $this->withSession(['current_company_id' => $companyA->id])
        ->put("/settings/master-data/room-types/{$foreignRoomType->id}", [
            'name' => 'Hijacked',
            'is_active' => true,
        ])
        ->assertNotFound();

    $this->withSession(['current_company_id' => $companyA->id])
        ->delete("/settings/master-data/room-types/{$foreignRoomType->id}")
        ->assertNotFound();

    expect($foreignRoomType->fresh()?->name)->toBe('Foreign Room');
});

test('room type referenced by accommodation history cannot be deleted', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Room Stay Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.room-types.view',
        'settings.master-data.room-types.delete',
    ]);

    $roomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'name' => 'Referenced Room',
    ]);

    CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'room_type_id' => $roomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->from(route('settings.master-data.room-types.index'))
        ->delete("/settings/master-data/room-types/{$roomType->id}")
        ->assertRedirect(route('settings.master-data.room-types.index'))
        ->assertSessionHasErrors('record');

    expect(RoomType::query()->whereKey($roomType->id)->exists())->toBeTrue();
});

test('inactive room type remains available through historical accommodation relationship', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Room Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $roomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'name' => 'Historical Room',
        'is_active' => true,
    ]);

    $stay = CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'room_type_id' => $roomType->id,
        'stay_type' => CrewAccommodationStayType::PostSignoff,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]);

    $roomType->update(['is_active' => false]);

    $stay->refresh()->load('roomType');

    expect($stay->roomType)->not->toBeNull()
        ->and($stay->roomType->is_active)->toBeFalse()
        ->and($stay->roomType->name)->toBe('Historical Room');
});
