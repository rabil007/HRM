<?php

use App\Models\Hotel;
use App\Models\RoomType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

test('hotel_id migration preserves existing room type records', function () {
    ['company' => $company] = makeCrewAssignmentFixtures();

    $legacyRoomType = RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => null,
        'name' => 'Legacy Preserved',
    ]);

    expect(Schema::hasColumn('room_types', 'hotel_id'))->toBeTrue()
        ->and(RoomType::query()->whereKey($legacyRoomType->id)->exists())->toBeTrue();
});

test('duplicate room type names across different hotels are allowed after migration', function () {
    ['company' => $company] = makeCrewAssignmentFixtures();

    $hotelA = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Rollback Hotel A']);
    $hotelB = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Rollback Hotel B']);

    RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotelA->id,
        'name' => 'Standard',
    ]);

    RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotelB->id,
        'name' => 'Standard',
    ]);

    expect(RoomType::query()->where('company_id', $company->id)->where('name', 'Standard')->count())->toBe(2);
});

test('rollback safely aborts before destructive changes when duplicate company names exist', function () {
    ['company' => $company] = makeCrewAssignmentFixtures();

    $hotelA = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Rollback A']);
    $hotelB = Hotel::factory()->create(['company_id' => $company->id, 'name' => 'Rollback B']);

    RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotelA->id,
        'name' => 'Duplicate Name',
    ]);

    RoomType::factory()->create([
        'company_id' => $company->id,
        'hotel_id' => $hotelB->id,
        'name' => 'Duplicate Name',
    ]);

    $migration = require database_path('migrations/2026_09_17_100000_add_hotel_id_to_room_types_table.php');

    expect($migration)->toBeInstanceOf(Migration::class);

    try {
        $migration->down();
        $failed = false;
    } catch (RuntimeException) {
        $failed = true;
    }

    expect($failed)->toBeTrue()
        ->and(Schema::hasColumn('room_types', 'hotel_id'))->toBeTrue();
});
