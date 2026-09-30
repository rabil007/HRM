<?php

use App\Support\MasterData\RankRemovalGuards;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('rank removal guards pass when rank schema is already absent', function () {
    expect(Schema::hasTable('ranks'))->toBeFalse();

    RankRemovalGuards::assertReadyOrFail();

    expect(true)->toBeTrue();
});

test('rank removal guards abort when employees have rank without position', function () {
    Schema::create('ranks', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    if (! Schema::hasColumn('employees', 'rank_id')) {
        Schema::table('employees', function (Blueprint $table): void {
            $table->unsignedBigInteger('rank_id')->nullable();
        });
    }

    $countryId = DB::table('countries')->insertGetId([
        'code' => 'G'.fake()->unique()->lexify('??'),
        'name' => 'Guard Land',
        'dial_code' => '+001',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $currencyId = DB::table('currencies')->insertGetId([
        'code' => 'G'.fake()->unique()->lexify('??'),
        'name' => 'Guard Currency',
        'symbol' => 'G$',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $companyId = DB::table('companies')->insertGetId([
        'name' => 'Guard Co',
        'slug' => 'guard-co-'.Str::lower(Str::random(6)),
        'working_days' => json_encode([1, 2, 3, 4, 5]),
        'country_id' => $countryId,
        'currency_id' => $currencyId,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('ranks')->insert([
        'name' => 'Orphan Rank',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rankId = (int) DB::table('ranks')->orderByDesc('id')->value('id');

    DB::table('employees')->insert([
        'company_id' => $companyId,
        'employee_no' => 'G-1',
        'name' => 'Orphan Employee',
        'rank_id' => $rankId,
        'position_id' => null,
        'status' => 'active',
        'salary_payment_method' => 'bank_transfer',
        'hire_date' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => RankRemovalGuards::assertReadyOrFail())
        ->toThrow(RuntimeException::class, 'employees_missing_position');
});
