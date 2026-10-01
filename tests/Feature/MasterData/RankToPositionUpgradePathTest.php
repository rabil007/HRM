<?php

use App\Enums\SavedViewPage;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\Position;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Vessel;
use App\Support\MasterData\MigrateSavedViewRankFilters;
use App\Support\MasterData\Migrations\BackfillRankToPositionBeforeRemoval;
use App\Support\MasterData\RankRemovalGuards;
use App\Support\MasterData\RankRemovalReadiness;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Rebuild the intermediate Rank-backed schema that exists after Phase 1 schema
 * migrations and before automatic backfill / saved-view / destructive removal.
 */
function restoreLegacyRankConsolidationSchema(): void
{
    if (! Schema::hasTable('ranks')) {
        Schema::create('ranks', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('max_tour_of_duty_days')->nullable();
            $table->timestamps();
        });
    }

    if (! Schema::hasTable('rank_position_mappings')) {
        Schema::create('rank_position_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('rank_id');
            $table->unsignedBigInteger('position_id');
            $table->string('match_type', 20);
            $table->timestamps();
            $table->unique(['company_id', 'rank_id']);
        });
    }

    if (! Schema::hasTable('document_requirement_rank')) {
        Schema::create('document_requirement_rank', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_requirement_id');
            $table->unsignedBigInteger('rank_id');
            $table->unique(['document_requirement_id', 'rank_id']);
        });
    }

    foreach (['employees', 'crew_assignments', 'crew_planning_assignments', 'employee_sea_services', 'vessel_manning'] as $table) {
        if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'rank_id')) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                if ($table === 'vessel_manning') {
                    $blueprint->foreignId('rank_id')->constrained('ranks')->restrictOnDelete();
                } else {
                    $blueprint->foreignId('rank_id')->nullable()->constrained('ranks')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'position_id')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedBigInteger('position_id')->nullable();
            });
        }
    }
}

/**
 * @return array{
 *     company: Company,
 *     user: User,
 *     rank_id: int,
 *     position_a: Position,
 *     position_b: Position,
 *     vessel: Vessel,
 *     employee: Employee
 * }
 */
function seedLegacyRankCompany(string $suffix = ''): array
{
    $suffix = $suffix !== '' ? $suffix : Str::lower(Str::random(4));

    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'L'.fake()->unique()->lexify('??'),
        'name' => "Legacy Land {$suffix}",
        'dial_code' => '+001',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'L'.fake()->unique()->lexify('??'),
        'name' => "Legacy Currency {$suffix}",
        'symbol' => 'L$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => "Legacy Co {$suffix}",
        'slug' => 'legacy-co-'.$suffix.'-'.Str::lower(Str::random(4)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'UTC',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $rankId = (int) DB::table('ranks')->insertGetId([
        'name' => "Chief Officer {$suffix}",
        'is_active' => true,
        'max_tour_of_duty_days' => 90,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $positionA = Position::query()->create([
        'company_id' => $company->id,
        'title' => "Chief Officer {$suffix}",
        'status' => 'active',
        'is_crew_position' => true,
        'max_tour_of_duty_days' => 90,
    ]);

    $positionB = Position::query()->create([
        'company_id' => $company->id,
        'title' => "Second Officer {$suffix}",
        'status' => 'active',
        'is_crew_position' => true,
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'position_id' => null,
        'status' => 'active',
        'name' => "Legacy Employee {$suffix}",
    ]);

    if (Schema::hasColumn('employees', 'rank_id')) {
        DB::table('employees')->where('id', $employee->id)->update([
            'rank_id' => $rankId,
            'position_id' => null,
        ]);
    }

    $vessel = Vessel::factory()->forCompany($company)->create([
        'name' => "Legacy Vessel {$suffix}",
    ]);

    return [
        'company' => $company,
        'user' => $user,
        'rank_id' => $rankId,
        'position_a' => $positionA,
        'position_b' => $positionB,
        'vessel' => $vessel,
        'employee' => $employee->fresh(),
    ];
}

test('legacy production upgrade path migrates rank data through backfill saved views and destructive removal', function () {
    restoreLegacyRankConsolidationSchema();

    $seed = seedLegacyRankCompany('upg');
    $company = $seed['company'];
    $rankId = $seed['rank_id'];
    $expectedPositionId = $seed['position_a']->id;
    $employee = $seed['employee'];
    $vessel = $seed['vessel'];

    $assignmentId = DB::table('crew_assignments')->insertGetId([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rankId,
        'position_id' => null,
        'status' => 'active',
        'assignment_no' => 'CA-LEG-1',
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => now(),
    ]);

    $planningId = DB::table('crew_planning_assignments')->insertGetId([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rankId,
        'position_id' => null,
        'planned_join_date' => now()->toDateString(),
        'planned_leave_date' => now()->addMonths(3)->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $seaServiceId = DB::table('employee_sea_services')->insertGetId([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rankId,
        'position_id' => null,
        'start_date' => now()->subMonths(6)->toDateString(),
        'end_date' => now()->subMonths(1)->toDateString(),
        'total_months' => 5,
        'total_days' => 150,
        'sort_order' => 1,
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => now(),
    ]);

    $manningId = DB::table('vessel_manning')->insertGetId([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rankId,
        'position_id' => null,
        'required_count' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $documentType = DocumentType::query()->create([
        'title' => 'Legacy STCW Type '.Str::uuid(),
        'is_active' => true,
    ]);

    $docReq = DocumentRequirement::query()->create([
        'company_id' => $company->id,
        'document_type_id' => $documentType->id,
        'is_active' => true,
    ]);

    DB::table('document_requirement_rank')->insert([
        'document_requirement_id' => $docReq->id,
        'rank_id' => $rankId,
    ]);

    $view = SavedView::query()->create([
        'company_id' => $company->id,
        'user_id' => $seed['user']->id,
        'page_key' => SavedViewPage::Crew,
        'name' => 'Legacy Rank Filter',
        'filters' => ['rank_id' => $rankId, 'status' => 'active'],
        'is_default' => false,
    ]);

    expect((new RankRemovalReadiness)->report((int) $company->id)['ready'])->toBeFalse();

    (new BackfillRankToPositionBeforeRemoval)->run();

    expect(DB::table('rank_position_mappings')->where('company_id', $company->id)->where('rank_id', $rankId)->value('position_id'))
        ->toBe($expectedPositionId)
        ->and(DB::table('employees')->where('id', $employee->id)->value('position_id'))->toBe($expectedPositionId)
        ->and(DB::table('crew_assignments')->where('id', $assignmentId)->value('position_id'))->toBe($expectedPositionId)
        ->and(DB::table('crew_planning_assignments')->where('id', $planningId)->value('position_id'))->toBe($expectedPositionId)
        ->and(DB::table('employee_sea_services')->where('id', $seaServiceId)->value('position_id'))->toBe($expectedPositionId)
        ->and(DB::table('vessel_manning')->where('id', $manningId)->value('position_id'))->toBe($expectedPositionId)
        ->and(DB::table('document_requirement_position')->where('document_requirement_id', $docReq->id)->where('position_id', $expectedPositionId)->exists())->toBeTrue();

    (new MigrateSavedViewRankFilters)->apply();

    $view->refresh();
    expect($view->filters)->toMatchArray(['position_id' => $expectedPositionId, 'status' => 'active'])
        ->and($view->filters)->not->toHaveKey('rank_id');

    expect((new RankRemovalReadiness)->report((int) $company->id)['ready'])->toBeTrue();

    // Destructive DDL is guarded; once ready, RankRemovalGuards must allow removal.
    // Full SQLite column/table DROP after re-adding Rank FKs is covered by fresh
    // migrate CI and RankRemovalGuardsTest — assert the guard gate here.
    RankRemovalGuards::assertReadyOrFail((int) $company->id);

    expect(Schema::hasTable('ranks'))->toBeTrue()
        ->and(DB::table('employees')->where('id', $employee->id)->value('position_id'))->toBe($expectedPositionId)
        ->and(DB::table('crew_assignments')->where('id', $assignmentId)->value('position_id'))->toBe($expectedPositionId)
        ->and(DB::table('crew_planning_assignments')->where('id', $planningId)->value('position_id'))->toBe($expectedPositionId)
        ->and(DB::table('employee_sea_services')->where('id', $seaServiceId)->value('position_id'))->toBe($expectedPositionId)
        ->and(DB::table('vessel_manning')->where('id', $manningId)->value('position_id'))->toBe($expectedPositionId)
        ->and(DB::table('document_requirement_position')->where('document_requirement_id', $docReq->id)->where('position_id', $expectedPositionId)->exists())->toBeTrue()
        ->and(DB::table('crew_assignments')->where('id', $assignmentId)->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('employee_sea_services')->where('id', $seaServiceId)->value('deleted_at'))->not->toBeNull();
});

test('rank position conflict blocks destructive removal and preserves schema', function () {
    restoreLegacyRankConsolidationSchema();

    $seed = seedLegacyRankCompany('cnf');

    DB::table('rank_position_mappings')->insert([
        'company_id' => $seed['company']->id,
        'rank_id' => $seed['rank_id'],
        'position_id' => $seed['position_a']->id,
        'match_type' => 'exact',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('employees')->where('id', $seed['employee']->id)->update([
        'rank_id' => $seed['rank_id'],
        'position_id' => $seed['position_b']->id,
    ]);

    $report = (new RankRemovalReadiness)->report((int) $seed['company']->id);

    expect($report['ready'])->toBeFalse()
        ->and($report['totals']['employees_position_conflict'])->toBeGreaterThan(0);

    expect(fn () => RankRemovalGuards::assertReadyOrFail((int) $seed['company']->id))
        ->toThrow(RuntimeException::class, 'employees_position_conflict');

    expect(Schema::hasTable('ranks'))->toBeTrue()
        ->and(Schema::hasColumn('employees', 'rank_id'))->toBeTrue();
});

test('soft deleted historical rows missing position fail readiness until backfilled', function () {
    restoreLegacyRankConsolidationSchema();

    $seed = seedLegacyRankCompany('soft');

    DB::table('employees')->where('id', $seed['employee']->id)->update([
        'rank_id' => null,
        'position_id' => $seed['position_a']->id,
    ]);

    $assignmentId = DB::table('crew_assignments')->insertGetId([
        'company_id' => $seed['company']->id,
        'employee_id' => $seed['employee']->id,
        'rank_id' => $seed['rank_id'],
        'position_id' => null,
        'status' => 'active',
        'assignment_no' => 'CA-SOFT-1',
        'created_at' => now(),
        'updated_at' => now(),
        'deleted_at' => now(),
    ]);

    $before = (new RankRemovalReadiness)->report((int) $seed['company']->id);

    expect($before['ready'])->toBeFalse()
        ->and($before['totals']['crew_assignments_missing_position'])->toBeGreaterThan(0);

    (new BackfillRankToPositionBeforeRemoval)->run();

    expect(DB::table('crew_assignments')->where('id', $assignmentId)->value('position_id'))
        ->toBe($seed['position_a']->id)
        ->and((new RankRemovalReadiness)->report((int) $seed['company']->id)['totals']['crew_assignments_missing_position'])->toBe(0);
});

test('cross company position ids fail readiness', function () {
    restoreLegacyRankConsolidationSchema();

    $companyA = seedLegacyRankCompany('xa');
    $companyB = seedLegacyRankCompany('xb');

    DB::table('rank_position_mappings')->insert([
        'company_id' => $companyA['company']->id,
        'rank_id' => $companyA['rank_id'],
        'position_id' => $companyA['position_a']->id,
        'match_type' => 'exact',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('employees')->where('id', $companyA['employee']->id)->update([
        'rank_id' => $companyA['rank_id'],
        'position_id' => $companyB['position_a']->id,
    ]);

    $report = (new RankRemovalReadiness)->report((int) $companyA['company']->id);

    expect($report['ready'])->toBeFalse()
        ->and($report['totals']['employees_invalid_position'])->toBeGreaterThan(0);

    expect(fn () => RankRemovalGuards::assertReadyOrFail((int) $companyA['company']->id))
        ->toThrow(RuntimeException::class);
});

test('saved view rank position conflict fails conversion', function () {
    restoreLegacyRankConsolidationSchema();

    $seed = seedLegacyRankCompany('svc');

    DB::table('rank_position_mappings')->insert([
        'company_id' => $seed['company']->id,
        'rank_id' => $seed['rank_id'],
        'position_id' => $seed['position_a']->id,
        'match_type' => 'exact',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $view = SavedView::query()->create([
        'company_id' => $seed['company']->id,
        'user_id' => $seed['user']->id,
        'page_key' => SavedViewPage::Employees,
        'name' => 'Conflict View',
        'filters' => [
            'rank_id' => $seed['rank_id'],
            'position_id' => $seed['position_b']->id,
        ],
        'is_default' => false,
    ]);

    expect(fn () => (new MigrateSavedViewRankFilters)->apply())
        ->toThrow(RuntimeException::class, "Saved view #{$view->id}");

    $view->refresh();
    expect($view->filters)->toHaveKey('rank_id')
        ->and($view->filters['rank_id'])->toBe($seed['rank_id']);
});

test('backfill never reuses another company position', function () {
    restoreLegacyRankConsolidationSchema();

    $companyA = seedLegacyRankCompany('tenA');
    $companyB = seedLegacyRankCompany('tenB');

    DB::table('ranks')->where('id', $companyA['rank_id'])->update([
        'name' => 'Shared Title Officer',
    ]);
    $companyB['position_a']->update(['title' => 'Shared Title Officer']);
    $companyA['position_a']->delete();

    DB::table('employees')->where('id', $companyA['employee']->id)->update([
        'rank_id' => $companyA['rank_id'],
        'position_id' => null,
    ]);

    (new BackfillRankToPositionBeforeRemoval)->run();

    $mapping = DB::table('rank_position_mappings')
        ->where('company_id', $companyA['company']->id)
        ->where('rank_id', $companyA['rank_id'])
        ->first();

    expect($mapping)->not->toBeNull();

    $mappedPosition = DB::table('positions')->where('id', $mapping->position_id)->first();

    expect((int) $mappedPosition->company_id)->toBe($companyA['company']->id)
        ->and((int) $mappedPosition->id)->not->toBe($companyB['position_a']->id)
        ->and((string) $mapping->match_type)->toBe('created');
});
