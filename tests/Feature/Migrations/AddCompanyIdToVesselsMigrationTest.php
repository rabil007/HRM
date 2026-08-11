<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Vessel;
use App\Models\VesselType;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function makeVesselCompanyMigrationFixtures(): array
{
    $country = Country::query()->create([
        'code' => 'VCM',
        'name' => 'Vessel Company Migration Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'VCM',
        'name' => 'Vessel Company Migration Currency',
        'symbol' => 'V$',
        'is_active' => true,
    ]);

    $companyA = Company::query()->create([
        'name' => 'Vessel Migration Co A',
        'slug' => 'vessel-migration-co-a',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $companyB = Company::query()->create([
        'name' => 'Vessel Migration Co B',
        'slug' => 'vessel-migration-co-b',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'Migration AHTS',
        'is_active' => true,
    ]);

    return compact('companyA', 'companyB', 'vesselType');
}

function vesselCompanyIdMigration()
{
    return require database_path('migrations/2026_08_10_171054_add_company_id_to_vessels_table.php');
}

test('rolling back company-owned vessels aborts when duplicate names exist across companies', function () {
    [
        'companyA' => $companyA,
        'companyB' => $companyB,
        'vesselType' => $vesselType,
    ] = makeVesselCompanyMigrationFixtures();

    $vesselA = Vessel::query()->create([
        'company_id' => $companyA->id,
        'name' => 'Ocean Star',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $vesselB = Vessel::query()->create([
        'company_id' => $companyB->id,
        'name' => 'Ocean Star',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    expect(Schema::hasColumn('vessels', 'company_id'))->toBeTrue();

    $migration = vesselCompanyIdMigration();

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'Cannot roll back company-owned vessels');

    expect(Schema::hasColumn('vessels', 'company_id'))->toBeTrue()
        ->and(Vessel::query()->find($vesselA->id)?->name)->toBe('Ocean Star')
        ->and(Vessel::query()->find($vesselB->id)?->name)->toBe('Ocean Star')
        ->and((int) Vessel::query()->find($vesselA->id)?->company_id)->toBe((int) $companyA->id)
        ->and((int) Vessel::query()->find($vesselB->id)?->company_id)->toBe((int) $companyB->id)
        ->and(DB::table('vessels')->where('id', $vesselA->id)->value('name'))->toBe('Ocean Star')
        ->and(DB::table('vessels')->where('id', $vesselB->id)->value('name'))->toBe('Ocean Star');
});

test('rolling back company-owned vessels succeeds when vessel names are globally unique', function () {
    [
        'companyA' => $companyA,
        'companyB' => $companyB,
        'vesselType' => $vesselType,
    ] = makeVesselCompanyMigrationFixtures();

    $vesselA = Vessel::query()->create([
        'company_id' => $companyA->id,
        'name' => 'Sea Eagle',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $vesselB = Vessel::query()->create([
        'company_id' => $companyB->id,
        'name' => 'Desert Falcon',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $migration = vesselCompanyIdMigration();
    $migration->down();

    $indexNames = collect(Schema::getIndexes('vessels'))->pluck('name');

    expect(Schema::hasColumn('vessels', 'company_id'))->toBeFalse()
        ->and(DB::table('vessels')->where('id', $vesselA->id)->value('name'))->toBe('Sea Eagle')
        ->and(DB::table('vessels')->where('id', $vesselB->id)->value('name'))->toBe('Desert Falcon')
        ->and($indexNames->contains('uq_vessel_records_name'))->toBeTrue()
        ->and($indexNames->contains('uq_vessels_company_name'))->toBeFalse();

    expect(fn () => DB::table('vessels')->insert([
        'name' => 'Sea Eagle',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    $migration->up();

    $restoredIndexNames = collect(Schema::getIndexes('vessels'))->pluck('name');

    expect(Schema::hasColumn('vessels', 'company_id'))->toBeTrue()
        ->and((int) DB::table('vessels')->where('id', $vesselA->id)->value('company_id'))->toBe(1)
        ->and((int) DB::table('vessels')->where('id', $vesselB->id)->value('company_id'))->toBe(1)
        ->and($restoredIndexNames->contains('uq_vessels_company_name'))->toBeTrue()
        ->and($restoredIndexNames->contains('uq_vessel_records_name'))->toBeFalse();
});
