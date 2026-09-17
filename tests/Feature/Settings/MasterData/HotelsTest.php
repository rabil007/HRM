<?php

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
use App\Enums\CrewPhaseCode;
use App\Models\Company;
use App\Models\Country;
use App\Models\CrewAccommodationStay;
use App\Models\Currency;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot access hotels page', function () {
    $this->get('/settings/master-data/hotels')->assertRedirect(route('login'));
});

test('authorized users can view, create, update, and delete hotels', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'HTL',
        'name' => 'Hotel Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'HTL',
        'name' => 'Hotel Currency',
        'symbol' => 'H$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Hotel Co',
        'slug' => 'hotel-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.view',
        'settings.master-data.hotels.create',
        'settings.master-data.hotels.update',
        'settings.master-data.hotels.delete',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get('/settings/master-data/hotels')
        ->assertOk();

    $this->withSession(['current_company_id' => $company->id])
        ->post('/settings/master-data/hotels', [
            'name' => 'Royal Rose',
            'description' => 'Airport hotel',
            'is_active' => true,
        ])->assertRedirect(route('settings.master-data.hotels.index'));

    $hotel = Hotel::query()->where('company_id', $company->id)->where('name', 'Royal Rose')->first();
    expect($hotel)->not->toBeNull();

    $this->withSession(['current_company_id' => $company->id])
        ->put("/settings/master-data/hotels/{$hotel->id}", [
            'name' => 'City Seasons',
            'description' => 'Updated description',
            'is_active' => false,
        ])->assertRedirect(route('settings.master-data.hotels.index'));

    $this->assertDatabaseHas('hotels', [
        'id' => $hotel->id,
        'company_id' => $company->id,
        'name' => 'City Seasons',
        'description' => 'Updated description',
        'is_active' => 0,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->delete("/settings/master-data/hotels/{$hotel->id}")
        ->assertRedirect(route('settings.master-data.hotels.index'));

    $this->assertDatabaseMissing('hotels', ['id' => $hotel->id]);
});

test('same hotel name is allowed in different companies', function () {
    ['user' => $user, 'company' => $companyA] = makeCrewAssignmentFixtures();
    ['company' => $companyB] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $companyA, ['settings.master-data.hotels.create']);
    grantCompanyPermissions($user, $companyB, ['settings.master-data.hotels.create']);

    Hotel::factory()->create([
        'company_id' => $companyA->id,
        'name' => 'Royal Rose',
    ]);

    $this->withSession(['current_company_id' => $companyB->id])
        ->post('/settings/master-data/hotels', [
            'name' => 'Royal Rose',
            'is_active' => true,
        ])
        ->assertRedirect(route('settings.master-data.hotels.index'));

    expect(Hotel::query()->where('company_id', $companyA->id)->where('name', 'Royal Rose')->exists())->toBeTrue()
        ->and(Hotel::query()->where('company_id', $companyB->id)->where('name', 'Royal Rose')->exists())->toBeTrue();
});

test('json quick-create scopes hotels to the current company', function () {
    ['user' => $user, 'company' => $companyA] = makeCrewAssignmentFixtures();
    ['company' => $companyB] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $companyA, ['settings.master-data.hotels.create']);
    grantCompanyPermissions($user, $companyB, ['settings.master-data.hotels.create']);

    $companyAHotel = Hotel::factory()->create([
        'company_id' => $companyA->id,
        'name' => 'Royal Rose',
    ]);

    $response = $this->withSession(['current_company_id' => $companyB->id])
        ->postJson('/settings/master-data/hotels', [
            'name' => 'Royal Rose',
            'is_active' => true,
        ]);

    $response->assertSuccessful();

    $companyBHotelId = (int) $response->json('id');

    expect($companyBHotelId)->not->toBe($companyAHotel->id)
        ->and(Hotel::query()->whereKey($companyBHotelId)->value('company_id'))->toBe($companyB->id);
});

test('unused hotel can be deleted and recreated with the same name', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeCrewAssignmentFixtures();

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.create',
        'settings.master-data.hotels.delete',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post('/settings/master-data/hotels', [
            'name' => 'Royal Rose',
            'is_active' => true,
        ])
        ->assertRedirect(route('settings.master-data.hotels.index'));

    $hotelId = Hotel::query()->where('company_id', $company->id)->where('name', 'Royal Rose')->value('id');
    expect($hotelId)->not->toBeNull();

    $this->withSession(['current_company_id' => $company->id])
        ->delete("/settings/master-data/hotels/{$hotelId}")
        ->assertRedirect(route('settings.master-data.hotels.index'));

    $this->assertDatabaseMissing('hotels', ['id' => $hotelId]);

    $this->withSession(['current_company_id' => $company->id])
        ->post('/settings/master-data/hotels', [
            'name' => 'Royal Rose',
            'is_active' => true,
        ])
        ->assertRedirect(route('settings.master-data.hotels.index'));

    expect(Hotel::query()->where('company_id', $company->id)->where('name', 'Royal Rose')->count())->toBe(1);
});

test('hotels index supports search and pagination meta', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'HPG',
        'name' => 'Hotel Page Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'HPG',
        'name' => 'Hotel Page Currency',
        'symbol' => 'P$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Hotel Page Co',
        'slug' => 'hotel-page-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.view',
    ]);

    Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Alpha Hotel', 'is_active' => true]);
    Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Beta Hotel', 'is_active' => true]);
    Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Other Label', 'is_active' => true]);

    $this->withSession(['current_company_id' => $company->id])
        ->get('/settings/master-data/hotels?search=Hotel&per_page=10')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/hotels')
            ->where('search', 'Hotel')
            ->where('pagination.per_page', 10)
            ->where('pagination.total', 2)
            ->has('hotels', 2)
        );
});

test('hotel names are unique per company', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeCrewAssignmentFixtures();

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.create',
    ]);

    Hotel::factory()->create([
        'company_id' => $company->id,
        'name' => 'Royal Rose',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post('/settings/master-data/hotels', [
            'name' => 'Royal Rose',
            'is_active' => true,
        ])
        ->assertSessionHasErrors('name');
});

test('users without hotel permissions are forbidden', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeCrewAssignmentFixtures();

    $this->withSession(['current_company_id' => $company->id])
        ->get('/settings/master-data/hotels')
        ->assertForbidden();
});

test('company a cannot access update or delete company b hotels', function () {
    ['user' => $user, 'company' => $companyA] = makeCrewAssignmentFixtures();
    ['company' => $companyB] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $companyA, [
        'settings.master-data.hotels.view',
        'settings.master-data.hotels.update',
        'settings.master-data.hotels.delete',
    ]);

    $foreignHotel = Hotel::factory()->create([
        'company_id' => $companyB->id,
        'name' => 'Foreign Hotel',
    ]);

    $this->withSession(['current_company_id' => $companyA->id])
        ->put("/settings/master-data/hotels/{$foreignHotel->id}", [
            'name' => 'Hijacked',
            'is_active' => true,
        ])
        ->assertNotFound();

    $this->withSession(['current_company_id' => $companyA->id])
        ->delete("/settings/master-data/hotels/{$foreignHotel->id}")
        ->assertNotFound();

    expect($foreignHotel->fresh()?->name)->toBe('Foreign Hotel');
});

test('hotel referenced by accommodation history cannot be deleted', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Hotel Stay Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.view',
        'settings.master-data.hotels.delete',
    ]);

    $hotel = Hotel::factory()->create([
        'company_id' => $company->id,
        'name' => 'Referenced Hotel',
    ]);

    CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->from(route('settings.master-data.hotels.index'))
        ->delete("/settings/master-data/hotels/{$hotel->id}")
        ->assertRedirect(route('settings.master-data.hotels.index'))
        ->assertSessionHasErrors('record');

    expect(Hotel::query()->whereKey($hotel->id)->exists())->toBeTrue();
});

test('inactive hotel remains available through historical accommodation relationship', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Historical Hotel Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $hotel = Hotel::factory()->create([
        'company_id' => $company->id,
        'name' => 'Historical Hotel',
        'is_active' => true,
    ]);

    $stay = CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]);

    $hotel->update(['is_active' => false]);

    $stay->refresh()->load('hotel');

    expect($stay->hotel)->not->toBeNull()
        ->and($stay->hotel->is_active)->toBeFalse()
        ->and($stay->hotel->name)->toBe('Historical Hotel');
});

test('hotel can be created with nested room types', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.create',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post('/settings/master-data/hotels', [
            'name' => 'Royal Rose',
            'description' => 'Airport hotel',
            'is_active' => true,
            'room_types' => [
                ['name' => 'Single Room', 'description' => 'One bed', 'is_active' => true],
                ['name' => 'Twin Room', 'description' => null, 'is_active' => true],
            ],
        ])
        ->assertRedirect(route('settings.master-data.hotels.index'));

    $hotel = Hotel::query()->where('company_id', $company->id)->where('name', 'Royal Rose')->first();

    expect($hotel)->not->toBeNull()
        ->and($hotel->roomTypes()->count())->toBe(2)
        ->and($hotel->roomTypes()->where('name', 'Single Room')->exists())->toBeTrue()
        ->and($hotel->roomTypes()->where('name', 'Twin Room')->exists())->toBeTrue();
});

test('room type belongs to hotel', function () {
    ['company' => $company] = makeCrewAssignmentFixtures();

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'City Seasons']);
    $roomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Suite',
    ]);

    expect($roomType->hotel_id)->toBe($hotel->id)
        ->and($roomType->hotel?->id)->toBe($hotel->id);
});

test('same room type name can exist in different hotels', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.create',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post('/settings/master-data/hotels', [
            'name' => 'Hotel A',
            'is_active' => true,
            'room_types' => [
                ['name' => 'Standard', 'is_active' => true],
            ],
        ])
        ->assertRedirect(route('settings.master-data.hotels.index'));

    $this->withSession(['current_company_id' => $company->id])
        ->post('/settings/master-data/hotels', [
            'name' => 'Hotel B',
            'is_active' => true,
            'room_types' => [
                ['name' => 'Standard', 'is_active' => true],
            ],
        ])
        ->assertRedirect(route('settings.master-data.hotels.index'));

    expect(RoomType::query()->where('company_id', $company->id)->where('name', 'Standard')->count())->toBe(2);
});

test('duplicate room type name inside the same hotel is rejected', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.create',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post('/settings/master-data/hotels', [
            'name' => 'Duplicate Hotel',
            'is_active' => true,
            'room_types' => [
                ['name' => 'Standard', 'is_active' => true],
                ['name' => 'Standard', 'is_active' => true],
            ],
        ])
        ->assertSessionHasErrors('room_types.1.name');
});

test('hotel update can add edit deactivate and remove unused room types', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.create',
        'settings.master-data.hotels.update',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post('/settings/master-data/hotels', [
            'name' => 'Managed Hotel',
            'is_active' => true,
            'room_types' => [
                ['name' => 'Single Room', 'is_active' => true],
                ['name' => 'Twin Room', 'is_active' => true],
            ],
        ])
        ->assertRedirect(route('settings.master-data.hotels.index'));

    $hotel = Hotel::query()->where('company_id', $company->id)->where('name', 'Managed Hotel')->firstOrFail();
    $singleRoom = $hotel->roomTypes()->where('name', 'Single Room')->firstOrFail();
    $twinRoom = $hotel->roomTypes()->where('name', 'Twin Room')->firstOrFail();

    $this->withSession(['current_company_id' => $company->id])
        ->put("/settings/master-data/hotels/{$hotel->id}", [
            'name' => 'Managed Hotel',
            'description' => 'Updated',
            'is_active' => true,
            'room_types' => [
                [
                    'id' => $singleRoom->id,
                    'name' => 'Single Room',
                    'description' => 'Updated single',
                    'is_active' => false,
                ],
                [
                    'id' => $twinRoom->id,
                    'name' => 'Twin Room',
                    'description' => null,
                    'is_active' => true,
                ],
                [
                    'name' => 'Suite',
                    'description' => 'Added later',
                    'is_active' => true,
                ],
            ],
        ])
        ->assertRedirect(route('settings.master-data.hotels.index'));

    $hotel->refresh()->load('roomTypes');

    expect($hotel->description)->toBe('Updated')
        ->and($hotel->roomTypes()->count())->toBe(3)
        ->and($singleRoom->fresh()?->is_active)->toBeFalse()
        ->and($singleRoom->fresh()?->description)->toBe('Updated single')
        ->and($hotel->roomTypes()->where('name', 'Suite')->exists())->toBeTrue();
});

test('nested room type id from another hotel is rejected on hotel update', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.update',
    ]);

    $hotelA = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Hotel A']);
    $hotelB = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Hotel B']);
    $foreignRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotelB->id,
        'name' => 'Foreign Room',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->put("/settings/master-data/hotels/{$hotelA->id}", [
            'name' => 'Hotel A',
            'is_active' => true,
            'room_types' => [
                [
                    'id' => $foreignRoomType->id,
                    'name' => 'Hijacked',
                    'is_active' => true,
                ],
            ],
        ])
        ->assertSessionHasErrors('room_types.0.id');

    expect($foreignRoomType->fresh()?->name)->toBe('Foreign Room');
});

test('referenced room type cannot be deleted through hotel update', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Room Type Hotel Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.update',
    ]);

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Referenced Hotel']);
    $roomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Referenced Room',
    ]);

    CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'room_type_id' => $roomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->put("/settings/master-data/hotels/{$hotel->id}", [
            'name' => 'Referenced Hotel',
            'is_active' => true,
            'room_types' => [],
        ])
        ->assertSessionHasErrors('record');

    expect(RoomType::query()->whereKey($roomType->id)->exists())->toBeTrue();
});

test('unused hotel deletes nested unused room types transactionally', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.create',
        'settings.master-data.hotels.delete',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post('/settings/master-data/hotels', [
            'name' => 'Disposable Hotel',
            'is_active' => true,
            'room_types' => [
                ['name' => 'Single Room', 'is_active' => true],
            ],
        ])
        ->assertRedirect(route('settings.master-data.hotels.index'));

    $hotel = Hotel::query()->where('company_id', $company->id)->where('name', 'Disposable Hotel')->firstOrFail();
    $roomTypeId = $hotel->roomTypes()->value('id');

    $this->withSession(['current_company_id' => $company->id])
        ->delete("/settings/master-data/hotels/{$hotel->id}")
        ->assertRedirect(route('settings.master-data.hotels.index'));

    expect(Hotel::query()->whereKey($hotel->id)->exists())->toBeFalse()
        ->and(RoomType::query()->whereKey($roomTypeId)->exists())->toBeFalse();
});

test('standalone room types page is no longer available', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.view',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get('/settings/master-data/room-types')
        ->assertNotFound();
});
