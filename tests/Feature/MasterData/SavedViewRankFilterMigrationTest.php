<?php

use App\Enums\SavedViewPage;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Position;
use App\Models\SavedView;
use App\Models\User;
use App\Support\MasterData\MigrateSavedViewRankFilters;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Rebuild the pre-Phase-3B mapping schema so the saved-view converter can be
 * exercised after migration 220000 has already dropped Rank tables in Pest.
 */
function restoreTemporaryRankMappingSchema(): void
{
    if (! Schema::hasTable('ranks')) {
        Schema::create('ranks', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('max_tour_of_duty_days')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    if (! Schema::hasTable('rank_position_mappings')) {
        Schema::create('rank_position_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('rank_id');
            $table->foreignId('position_id')->constrained('positions')->restrictOnDelete();
            $table->string('match_type');
            $table->timestamps();
            $table->unique(['company_id', 'rank_id']);
        });
    }
}

function makeSavedViewMigrationCompany(): Company
{
    $country = Country::query()->create([
        'code' => 'SV'.fake()->unique()->numerify('##'),
        'name' => 'Saved View Land',
        'dial_code' => '+001',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'SV'.fake()->unique()->numerify('##'),
        'name' => 'Saved View Currency',
        'symbol' => 'S$',
        'is_active' => true,
    ]);

    return Company::query()->create([
        'name' => 'Saved View Co',
        'slug' => 'saved-view-'.Str::lower(Str::random(6)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

test('saved view rank filters migrate to mapped position_id', function () {
    restoreTemporaryRankMappingSchema();

    $user = User::factory()->create();
    $company = makeSavedViewMigrationCompany();

    $rankId = DB::table('ranks')->insertGetId([
        'name' => 'Legacy Chief Officer',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Chief Officer',
        'status' => 'active',
        'is_crew_position' => true,
    ]);

    DB::table('rank_position_mappings')->insert([
        'company_id' => $company->id,
        'rank_id' => $rankId,
        'position_id' => $position->id,
        'match_type' => 'exact',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $view = SavedView::query()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'page_key' => SavedViewPage::Crew,
        'name' => 'Legacy Rank Filter',
        'filters' => [
            'rank_id' => $rankId,
            'status' => 'active',
        ],
        'is_default' => false,
    ]);

    $result = (new MigrateSavedViewRankFilters)->apply();

    expect($result['converted'])->toBe(1);

    $view->refresh();

    expect($view->filters)->toMatchArray([
        'position_id' => $position->id,
        'status' => 'active',
    ])
        ->and($view->filters)->not->toHaveKey('rank_id');
});

test('saved view rank filter migration fails when mapping is missing', function () {
    restoreTemporaryRankMappingSchema();

    $user = User::factory()->create();
    $company = makeSavedViewMigrationCompany();

    SavedView::query()->create([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'page_key' => SavedViewPage::Employees,
        'name' => 'Broken Rank Filter',
        'filters' => ['rank_id' => 999999],
        'is_default' => false,
    ]);

    expect(fn () => (new MigrateSavedViewRankFilters)->apply())
        ->toThrow(RuntimeException::class, 'no tenant Rank→Position mapping');
});
