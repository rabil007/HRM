<?php

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
use App\Enums\CrewPhaseCode;
use App\Models\Company;
use App\Models\Country;
use App\Models\CrewAccommodationStay;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\Hotel;
use App\Models\RoomType;
use App\Models\User;
use App\Support\MasterData\ReconcileLegacyRoomTypes;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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

test('hotel update without room_types preserves existing room types', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.update',
    ]);

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Preserved Hotel']);
    $roomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Preserved Room',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->put("/settings/master-data/hotels/{$hotel->id}", [
            'name' => 'Renamed Hotel',
            'description' => 'Updated only',
            'is_active' => true,
        ])
        ->assertRedirect(route('settings.master-data.hotels.index'));

    expect($hotel->fresh()?->name)->toBe('Renamed Hotel')
        ->and(RoomType::query()->whereKey($roomType->id)->exists())->toBeTrue()
        ->and($roomType->fresh()?->name)->toBe('Preserved Room');
});

test('hotel update with empty room_types and no removed ids preserves existing room types', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.update',
    ]);

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Empty Payload Hotel']);
    $roomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Still Here',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->put("/settings/master-data/hotels/{$hotel->id}", [
            'name' => 'Empty Payload Hotel',
            'is_active' => true,
            'room_types' => [],
        ])
        ->assertRedirect(route('settings.master-data.hotels.index'));

    expect(RoomType::query()->whereKey($roomType->id)->exists())->toBeTrue();
});

test('only room types listed in removed_room_type_ids are deleted', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.update',
    ]);

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Removal Hotel']);
    $keep = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Keep',
    ]);
    $remove = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Remove',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->put("/settings/master-data/hotels/{$hotel->id}", [
            'name' => 'Removal Hotel',
            'is_active' => true,
            'removed_room_type_ids' => [$remove->id],
        ])
        ->assertRedirect(route('settings.master-data.hotels.index'));

    expect(RoomType::query()->whereKey($keep->id)->exists())->toBeTrue()
        ->and(RoomType::query()->whereKey($remove->id)->exists())->toBeFalse();
});

test('stale hotel payload does not remove a room type created concurrently', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.update',
    ]);

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Concurrent Hotel']);
    $singleRoom = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Single Room',
    ]);

    $suite = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Suite',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->put("/settings/master-data/hotels/{$hotel->id}", [
            'name' => 'Concurrent Hotel',
            'is_active' => true,
            'room_types' => [
                [
                    'id' => $singleRoom->id,
                    'name' => 'Single Room',
                    'is_active' => true,
                ],
            ],
        ])
        ->assertRedirect(route('settings.master-data.hotels.index'));

    expect(RoomType::query()->whereKey($suite->id)->exists())->toBeTrue();
});

test('create-only user can enter room types while creating hotel', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.view',
        'settings.master-data.hotels.create',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post('/settings/master-data/hotels', [
            'name' => 'Create Only Hotel',
            'is_active' => true,
            'room_types' => [
                ['name' => 'Single Room', 'is_active' => true],
            ],
        ])
        ->assertRedirect(route('settings.master-data.hotels.index'));

    $hotel = Hotel::query()->where('company_id', $company->id)->where('name', 'Create Only Hotel')->first();

    expect($hotel)->not->toBeNull()
        ->and($hotel->roomTypes()->where('name', 'Single Room')->exists())->toBeTrue();
});

test('create-only user cannot edit an existing hotel', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.view',
        'settings.master-data.hotels.create',
    ]);

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Locked Hotel']);

    $this->withSession(['current_company_id' => $company->id])
        ->put("/settings/master-data/hotels/{$hotel->id}", [
            'name' => 'Hijacked',
            'is_active' => true,
        ])
        ->assertForbidden();
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
            'removed_room_type_ids' => [$roomType->id],
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

test('legacy room type used by one hotel is auto-reconciled', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Legacy Single Hotel Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Legacy Hotel']);
    $legacyRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => null,
        'name' => 'Twin',
    ]);

    CrewAccommodationStay::withoutEvents(fn () => CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'room_type_id' => $legacyRoomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]));

    ReconcileLegacyRoomTypes::autoReconcile();

    expect($legacyRoomType->fresh()?->hotel_id)->toBe($hotel->id);
});

test('unused legacy room type is not auto-assigned', function () {
    ['company' => $company] = makeCrewAssignmentFixtures();

    $legacyRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => null,
        'name' => 'Unused Suite',
    ]);

    ReconcileLegacyRoomTypes::autoReconcile();

    expect($legacyRoomType->fresh()?->hotel_id)->toBeNull();
});

test('legacy room type with multiple hotel usage can be split by hotel', function () {
    ['company' => $company, 'employee' => $employeeA, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employeeB = Employee::factory()->forCompany($company)->create([
        'name' => 'Legacy Split B',
    ]);
    $vessel = makeCrewMovementVessel('Legacy Split Vessel', $company);
    $assignmentA = makeCurrentCrewPhaseAssignment($company, $employeeA, $rank, $vessel, CrewPhaseCode::JoinStandby);
    $assignmentB = makeCurrentCrewPhaseAssignment($company, $employeeB, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $hotelA = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Hotel A']);
    $hotelB = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Hotel B']);
    $legacyRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => null,
        'name' => 'Standard',
    ]);

    $stayA = CrewAccommodationStay::withoutEvents(fn () => CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignmentA->id,
        'hotel_id' => $hotelA->id,
        'room_type_id' => $legacyRoomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]));

    $stayB = CrewAccommodationStay::withoutEvents(fn () => CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignmentB->id,
        'hotel_id' => $hotelB->id,
        'room_type_id' => $legacyRoomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]));

    ReconcileLegacyRoomTypes::splitByHotelUsage($legacyRoomType, $company->id);

    $stayA->refresh();
    $stayB->refresh();

    expect($legacyRoomType->fresh()?->hotel_id)->toBe($hotelA->id)
        ->and($stayA->room_type_id)->toBe($legacyRoomType->id)
        ->and($stayB->room_type_id)->not->toBe($legacyRoomType->id)
        ->and(RoomType::query()->where('hotel_id', $hotelB->id)->where('name', 'Standard')->exists())->toBeTrue();
});

test('legacy room type delete permission maps to hotels update not hotels delete', function () {
    $legacyDelete = Permission::query()->firstOrCreate([
        'name' => 'settings.master-data.room-types.delete',
        'guard_name' => 'web',
    ]);
    $hotelsUpdate = Permission::query()->firstOrCreate([
        'name' => 'settings.master-data.hotels.update',
        'guard_name' => 'web',
    ]);
    $hotelsDelete = Permission::query()->firstOrCreate([
        'name' => 'settings.master-data.hotels.delete',
        'guard_name' => 'web',
    ]);

    ['company' => $company] = makeCrewAssignmentFixtures();

    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'legacy-room-type-delete',
        'guard_name' => 'web',
    ]);
    $role->syncPermissions([$legacyDelete]);

    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);

    $role->refresh();

    expect($role->hasPermissionTo($hotelsUpdate))->toBeTrue()
        ->and($role->hasPermissionTo($hotelsDelete))->toBeFalse();
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

test('hotel deletion rolls back when a nested room type is referenced', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Rollback Hotel Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.delete',
    ]);

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Rollback Hotel']);
    $referencedRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Referenced Room',
    ]);
    $unusedRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Unused Room',
    ]);

    CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'room_type_id' => $referencedRoomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->from(route('settings.master-data.hotels.index'))
        ->delete("/settings/master-data/hotels/{$hotel->id}")
        ->assertRedirect(route('settings.master-data.hotels.index'))
        ->assertSessionHasErrors('record');

    expect(Hotel::query()->whereKey($hotel->id)->exists())->toBeTrue()
        ->and(RoomType::query()->whereKey($referencedRoomType->id)->exists())->toBeTrue()
        ->and(RoomType::query()->whereKey($unusedRoomType->id)->exists())->toBeTrue();
});

test('legacy room type can be assigned safely to a valid hotel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Assign Legacy Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.update',
    ]);

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Assign Hotel']);
    $legacyRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => null,
        'name' => 'Assignable Standard',
    ]);

    CrewAccommodationStay::withoutEvents(fn () => CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'room_type_id' => $legacyRoomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]));

    $this->withSession(['current_company_id' => $company->id])
        ->post('/settings/master-data/hotels/legacy-room-types/assign', [
            'room_type_id' => $legacyRoomType->id,
            'hotel_id' => $hotel->id,
        ])
        ->assertRedirect(route('settings.master-data.hotels.index'));

    expect($legacyRoomType->fresh()?->hotel_id)->toBe($hotel->id);
});

test('legacy room type already assigned cannot be reassigned', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.update',
    ]);

    $hotelA = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Hotel A']);
    $hotelB = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Hotel B']);
    $assignedRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotelA->id,
        'name' => 'Assigned Standard',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->post('/settings/master-data/hotels/legacy-room-types/assign', [
            'room_type_id' => $assignedRoomType->id,
            'hotel_id' => $hotelB->id,
        ])
        ->assertSessionHasErrors('room_type_id');

    expect($assignedRoomType->fresh()?->hotel_id)->toBe($hotelA->id);
});

test('cross-company legacy room type cannot be assigned', function () {
    ['user' => $user, 'company' => $companyA] = makeCrewAssignmentFixtures();
    ['company' => $companyB] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $companyA, [
        'settings.master-data.hotels.update',
    ]);

    $targetHotel = Hotel::factory()->create(['company_id' => $companyA->id, 'name' => 'Target Hotel']);
    $foreignLegacyRoomType = RoomType::factory()->create([
        'company_id' => $companyB->id,
        'hotel_id' => null,
        'name' => 'Foreign Legacy',
    ]);

    $this->withSession(['current_company_id' => $companyA->id])
        ->post('/settings/master-data/hotels/legacy-room-types/assign', [
            'room_type_id' => $foreignLegacyRoomType->id,
            'hotel_id' => $targetHotel->id,
        ])
        ->assertSessionHasErrors('room_type_id');

    expect($foreignLegacyRoomType->fresh()?->hotel_id)->toBeNull();
});

test('cross-company hotel cannot be targeted for legacy assignment', function () {
    ['user' => $user, 'company' => $companyA] = makeCrewAssignmentFixtures();
    ['company' => $companyB] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $companyA, [
        'settings.master-data.hotels.update',
    ]);

    $foreignHotel = Hotel::factory()->create(['company_id' => $companyB->id, 'name' => 'Foreign Hotel']);
    $legacyRoomType = RoomType::factory()->create([
        'company_id' => $companyA->id,
        'hotel_id' => null,
        'name' => 'Local Legacy',
    ]);

    $this->withSession(['current_company_id' => $companyA->id])
        ->post('/settings/master-data/hotels/legacy-room-types/assign', [
            'room_type_id' => $legacyRoomType->id,
            'hotel_id' => $foreignHotel->id,
        ])
        ->assertSessionHasErrors('hotel_id');

    expect($legacyRoomType->fresh()?->hotel_id)->toBeNull();
});

test('legacy room type used historically by hotel a cannot be manually assigned to hotel b', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Wrong Hotel Assign Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.update',
    ]);

    $hotelA = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Historical Hotel A']);
    $hotelB = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Historical Hotel B']);
    $legacyRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => null,
        'name' => 'Historical Standard',
    ]);

    CrewAccommodationStay::withoutEvents(fn () => CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotelA->id,
        'room_type_id' => $legacyRoomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]));

    $this->withSession(['current_company_id' => $company->id])
        ->post('/settings/master-data/hotels/legacy-room-types/assign', [
            'room_type_id' => $legacyRoomType->id,
            'hotel_id' => $hotelB->id,
        ])
        ->assertSessionHasErrors('hotel_id');

    expect($legacyRoomType->fresh()?->hotel_id)->toBeNull();
});

test('duplicate room type name in target hotel blocks manual legacy assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Duplicate Assign Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.update',
    ]);

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Duplicate Assign Hotel']);
    RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Standard',
    ]);
    $legacyRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => null,
        'name' => 'Standard',
    ]);

    CrewAccommodationStay::withoutEvents(fn () => CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'room_type_id' => $legacyRoomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]));

    $this->withSession(['current_company_id' => $company->id])
        ->post('/settings/master-data/hotels/legacy-room-types/assign', [
            'room_type_id' => $legacyRoomType->id,
            'hotel_id' => $hotel->id,
        ])
        ->assertSessionHasErrors('name');

    expect($legacyRoomType->fresh()?->hotel_id)->toBeNull()
        ->and(RoomType::query()->where('hotel_id', $hotel->id)->where('name', 'Standard')->count())->toBe(1);
});

test('split reconciliation reuses existing same-name room type in destination hotel', function () {
    ['company' => $company, 'employee' => $employeeA, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employeeB = Employee::factory()->forCompany($company)->create(['name' => 'Reuse Split B']);
    $vessel = makeCrewMovementVessel('Reuse Split Vessel', $company);
    $assignmentA = makeCurrentCrewPhaseAssignment($company, $employeeA, $rank, $vessel, CrewPhaseCode::JoinStandby);
    $assignmentB = makeCurrentCrewPhaseAssignment($company, $employeeB, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $hotelA = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Reuse Hotel A']);
    $hotelB = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Reuse Hotel B']);
    $existingHotelBRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotelB->id,
        'name' => 'Standard',
    ]);
    $legacyRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => null,
        'name' => 'Standard',
    ]);

    $stayA = CrewAccommodationStay::withoutEvents(fn () => CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignmentA->id,
        'hotel_id' => $hotelA->id,
        'room_type_id' => $legacyRoomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]));

    $stayB = CrewAccommodationStay::withoutEvents(fn () => CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignmentB->id,
        'hotel_id' => $hotelB->id,
        'room_type_id' => $legacyRoomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]));

    ReconcileLegacyRoomTypes::splitByHotelUsage($legacyRoomType, $company->id);

    $stayA->refresh();
    $stayB->refresh();

    expect($legacyRoomType->fresh()?->hotel_id)->toBe($hotelA->id)
        ->and($stayA->room_type_id)->toBe($legacyRoomType->id)
        ->and($stayB->room_type_id)->toBe($existingHotelBRoomType->id)
        ->and(RoomType::query()->where('hotel_id', $hotelB->id)->where('name', 'Standard')->count())->toBe(1);
});

test('split reconciliation keeps hotel room type and stay relationships consistent', function () {
    ['company' => $company, 'employee' => $employeeA, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employeeB = Employee::factory()->forCompany($company)->create(['name' => 'Consistency Split B']);
    $vessel = makeCrewMovementVessel('Consistency Split Vessel', $company);
    $assignmentA = makeCurrentCrewPhaseAssignment($company, $employeeA, $rank, $vessel, CrewPhaseCode::JoinStandby);
    $assignmentB = makeCurrentCrewPhaseAssignment($company, $employeeB, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $hotelA = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Consistency Hotel A']);
    $hotelB = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Consistency Hotel B']);
    $legacyRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => null,
        'name' => 'Deluxe',
    ]);

    $stayA = CrewAccommodationStay::withoutEvents(fn () => CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignmentA->id,
        'hotel_id' => $hotelA->id,
        'room_type_id' => $legacyRoomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]));

    $stayB = CrewAccommodationStay::withoutEvents(fn () => CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignmentB->id,
        'hotel_id' => $hotelB->id,
        'room_type_id' => $legacyRoomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]));

    ReconcileLegacyRoomTypes::splitByHotelUsage($legacyRoomType, $company->id);

    foreach ([$stayA->fresh(), $stayB->fresh()] as $stay) {
        $roomType = RoomType::query()->find($stay->room_type_id);

        expect($roomType)->not->toBeNull()
            ->and($roomType->company_id)->toBe($company->id)
            ->and($roomType->hotel_id)->toBe($stay->hotel_id);
    }
});

test('split reconciliation reassigns to existing same-name room types when every hotel already has one', function () {
    ['company' => $company, 'employee' => $employeeA, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employeeB = Employee::factory()->forCompany($company)->create(['name' => 'All Existing Split B']);
    $vessel = makeCrewMovementVessel('All Existing Split Vessel', $company);
    $assignmentA = makeCurrentCrewPhaseAssignment($company, $employeeA, $rank, $vessel, CrewPhaseCode::JoinStandby);
    $assignmentB = makeCurrentCrewPhaseAssignment($company, $employeeB, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $hotelA = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'All Existing Hotel A']);
    $hotelB = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'All Existing Hotel B']);
    $existingHotelARoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotelA->id,
        'name' => 'Standard',
    ]);
    $existingHotelBRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotelB->id,
        'name' => 'Standard',
    ]);
    $legacyRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => null,
        'name' => 'Standard',
    ]);

    $stayA = CrewAccommodationStay::withoutEvents(fn () => CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignmentA->id,
        'hotel_id' => $hotelA->id,
        'room_type_id' => $legacyRoomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]));

    $stayB = CrewAccommodationStay::withoutEvents(fn () => CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignmentB->id,
        'hotel_id' => $hotelB->id,
        'room_type_id' => $legacyRoomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]));

    ReconcileLegacyRoomTypes::autoReconcile();

    expect($legacyRoomType->fresh()?->hotel_id)->toBeNull()
        ->and($stayA->fresh()->room_type_id)->toBe($existingHotelARoomType->id)
        ->and($stayB->fresh()->room_type_id)->toBe($existingHotelBRoomType->id)
        ->and(RoomType::query()->where('hotel_id', $hotelA->id)->where('name', 'Standard')->count())->toBe(1)
        ->and(RoomType::query()->where('hotel_id', $hotelB->id)->where('name', 'Standard')->count())->toBe(1);
});

test('auto reconciliation leaves duplicate-name single-hotel legacy data unresolved', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Unresolved Auto Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Unresolved Auto Hotel']);
    RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Standard',
    ]);
    $legacyRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => null,
        'name' => 'Standard',
    ]);

    CrewAccommodationStay::withoutEvents(fn () => CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $hotel->id,
        'room_type_id' => $legacyRoomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]));

    ReconcileLegacyRoomTypes::autoReconcile();

    expect($legacyRoomType->fresh()?->hotel_id)->toBeNull();
});

test('reconciliation updates historical stays through model save and records activity history', function () {
    ['company' => $company, 'employee' => $employeeA, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $employeeB = Employee::factory()->forCompany($company)->create(['name' => 'Audit Split B']);
    $vessel = makeCrewMovementVessel('Audit Split Vessel', $company);
    $assignmentA = makeCurrentCrewPhaseAssignment($company, $employeeA, $rank, $vessel, CrewPhaseCode::JoinStandby);
    $assignmentB = makeCurrentCrewPhaseAssignment($company, $employeeB, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $hotelA = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Audit Hotel A']);
    $hotelB = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Audit Hotel B']);
    $legacyRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => null,
        'name' => 'Audit Standard',
    ]);

    $stayA = CrewAccommodationStay::withoutEvents(fn () => CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignmentA->id,
        'hotel_id' => $hotelA->id,
        'room_type_id' => $legacyRoomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]));

    $stayB = CrewAccommodationStay::withoutEvents(fn () => CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignmentB->id,
        'hotel_id' => $hotelB->id,
        'room_type_id' => $legacyRoomType->id,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => now()->toDateString(),
    ]));

    $activityCountBefore = Activity::query()
        ->where('subject_type', CrewAccommodationStay::class)
        ->count();

    ReconcileLegacyRoomTypes::splitByHotelUsage($legacyRoomType, $company->id);

    $stayB->refresh();

    $activity = Activity::query()
        ->where('subject_type', CrewAccommodationStay::class)
        ->where('subject_id', $stayB->id)
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    expect(Activity::query()->where('subject_type', CrewAccommodationStay::class)->count())
        ->toBeGreaterThan($activityCountBefore)
        ->and($activity)->not->toBeNull()
        ->and($activity->company_id)->toBe($company->id)
        ->and($activity->properties->get('reason'))->toBe('legacy_room_type_reconciliation')
        ->and($activity->properties->get('legacy_room_type_id'))->toBe($legacyRoomType->id)
        ->and($activity->properties->get('hotel_id'))->toBe($hotelB->id)
        ->and($activity->attribute_changes->get('attributes')['room_type_id'])->not->toBe($legacyRoomType->id)
        ->and($activity->attribute_changes->get('old')['room_type_id'])->toBe($legacyRoomType->id)
        ->and($stayA->fresh()->room_type_id)->toBe($legacyRoomType->id);
});

test('duplicate room type ids in nested hotel update payload are rejected', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.update',
    ]);

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Duplicate Id Hotel']);
    $roomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Single',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->put("/settings/master-data/hotels/{$hotel->id}", [
            'name' => 'Duplicate Id Hotel',
            'is_active' => true,
            'room_types' => [
                [
                    'id' => $roomType->id,
                    'name' => 'Single',
                    'is_active' => true,
                ],
                [
                    'id' => $roomType->id,
                    'name' => 'Single Updated Again',
                    'is_active' => true,
                ],
            ],
        ])
        ->assertSessionHasErrors('room_types.1.id');

    expect($roomType->fresh()?->name)->toBe('Single');
});

test('same room type id cannot appear in room_types and removed_room_type_ids', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();

    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.hotels.update',
    ]);

    $hotel = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Overlap Hotel']);
    $roomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Overlap Room',
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->put("/settings/master-data/hotels/{$hotel->id}", [
            'name' => 'Overlap Hotel',
            'is_active' => true,
            'room_types' => [
                [
                    'id' => $roomType->id,
                    'name' => 'Overlap Room',
                    'is_active' => true,
                ],
            ],
            'removed_room_type_ids' => [$roomType->id],
        ])
        ->assertSessionHasErrors('removed_room_type_ids');

    expect(RoomType::query()->whereKey($roomType->id)->exists())->toBeTrue();
});
