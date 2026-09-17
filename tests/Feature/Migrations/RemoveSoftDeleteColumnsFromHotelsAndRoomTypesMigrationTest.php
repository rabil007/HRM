<?php

use App\Enums\CrewAccommodationStatus;
use App\Enums\CrewAccommodationStayType;
use App\Enums\CrewPhaseCode;
use App\Models\CrewAccommodationStay;
use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function removeHotelRoomTypeSoftDeleteMigration(): object
{
    return require database_path('migrations/2026_09_16_150000_remove_soft_delete_columns_from_hotels_and_room_types.php');
}

test('fresh migrated database has no deleted_at on hotels or room types', function () {
    expect(Schema::hasColumn('hotels', 'deleted_at'))->toBeFalse()
        ->and(Schema::hasColumn('room_types', 'deleted_at'))->toBeFalse();
});

test('migration removes unused legacy soft-deleted hotels and room types', function () {
    ['company' => $company] = makeCrewAssignmentFixtures();

    $migration = removeHotelRoomTypeSoftDeleteMigration();
    $migration->down();

    $activeHotelId = DB::table('hotels')->insertGetId([
        'company_id' => $company->id,
        'name' => 'Active Hotel',
        'description' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => null,
    ]);

    $unusedDeletedHotelId = DB::table('hotels')->insertGetId([
        'company_id' => $company->id,
        'name' => 'Unused Deleted Hotel',
        'description' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => now(),
    ]);

    $activeRoomTypeId = DB::table('room_types')->insertGetId([
        'company_id' => $company->id,
        'hotel_id' => $activeHotelId,
        'name' => 'Active Room',
        'description' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => null,
    ]);

    $unusedDeletedRoomTypeId = DB::table('room_types')->insertGetId([
        'company_id' => $company->id,
        'name' => 'Unused Deleted Room',
        'description' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('hotels')->where('id', $activeHotelId)->exists())->toBeTrue()
        ->and(DB::table('hotels')->where('id', $unusedDeletedHotelId)->exists())->toBeFalse()
        ->and(DB::table('room_types')->where('id', $activeRoomTypeId)->exists())->toBeTrue()
        ->and(DB::table('room_types')->where('id', $unusedDeletedRoomTypeId)->exists())->toBeFalse()
        ->and(Schema::hasColumn('hotels', 'deleted_at'))->toBeFalse()
        ->and(Schema::hasColumn('room_types', 'deleted_at'))->toBeFalse();
});

test('migration preserves referenced legacy soft-deleted hotels and room types as inactive', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $vessel = makeCrewMovementVessel('Migration Preserve Vessel', $company);
    $assignment = makeCurrentCrewPhaseAssignment($company, $employee, $rank, $vessel, CrewPhaseCode::JoinStandby);

    $migration = removeHotelRoomTypeSoftDeleteMigration();
    $migration->down();

    $referencedHotelId = DB::table('hotels')->insertGetId([
        'company_id' => $company->id,
        'name' => 'Referenced Deleted Hotel',
        'description' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => now(),
    ]);

    $referencedRoomTypeId = DB::table('room_types')->insertGetId([
        'company_id' => $company->id,
        'hotel_id' => $referencedHotelId,
        'name' => 'Referenced Deleted Room',
        'description' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => now(),
    ]);

    $stay = CrewAccommodationStay::factory()->create([
        'company_id' => $company->id,
        'crew_assignment_id' => $assignment->id,
        'hotel_id' => $referencedHotelId,
        'room_type_id' => $referencedRoomTypeId,
        'stay_type' => CrewAccommodationStayType::PreJoin,
        'accommodation_status' => CrewAccommodationStatus::Hotel,
        'check_in_date' => '2026-09-16',
    ]);

    $migration->up();

    $hotel = DB::table('hotels')->where('id', $referencedHotelId)->first();
    $roomType = DB::table('room_types')->where('id', $referencedRoomTypeId)->first();

    expect($hotel)->not->toBeNull()
        ->and((bool) $hotel->is_active)->toBeFalse()
        ->and($roomType)->not->toBeNull()
        ->and((bool) $roomType->is_active)->toBeFalse()
        ->and(Schema::hasColumn('hotels', 'deleted_at'))->toBeFalse()
        ->and(Schema::hasColumn('room_types', 'deleted_at'))->toBeFalse()
        ->and(CrewAccommodationStay::query()->find($stay->id)?->hotel_id)->toBe($referencedHotelId)
        ->and(CrewAccommodationStay::query()->find($stay->id)?->room_type_id)->toBe($referencedRoomTypeId);
});

test('migration down restores deleted_at columns without recreating removed legacy rows', function () {
    ['company' => $company] = makeCrewAssignmentFixtures();

    $migration = removeHotelRoomTypeSoftDeleteMigration();
    $migration->down();

    $unusedDeletedHotelId = DB::table('hotels')->insertGetId([
        'company_id' => $company->id,
        'name' => 'Rollback Deleted Hotel',
        'description' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => now(),
    ]);

    $migration->up();

    expect(DB::table('hotels')->where('id', $unusedDeletedHotelId)->exists())->toBeFalse();

    $migration->down();

    expect(Schema::hasColumn('hotels', 'deleted_at'))->toBeTrue()
        ->and(Schema::hasColumn('room_types', 'deleted_at'))->toBeTrue()
        ->and(DB::table('hotels')->where('id', $unusedDeletedHotelId)->exists())->toBeFalse();
});

test('eloquent hotel and room type models do not use soft deletes after migration', function () {
    ['company' => $company] = makeCrewAssignmentFixtures();

    $hotel = Hotel::query()->create([
        'company_id' => $company->id,
        'name' => 'Model Hotel',
        'is_active' => true,
    ]);

    $roomType = RoomType::query()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotel->id,
        'name' => 'Model Room',
        'is_active' => true,
    ]);

    $roomType->delete();
    $hotel->delete();

    expect(RoomType::query()->where('id', $roomType->id)->exists())->toBeFalse()
        ->and(Hotel::query()->where('id', $hotel->id)->exists())->toBeFalse();
});
