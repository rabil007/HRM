<?php

use App\Enums\RankPositionMatchType;
use App\Models\Company;
use App\Models\Country;
use App\Models\CrewPlanningAssignment;
use App\Models\Currency;
use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeSeaService;
use App\Models\Position;
use App\Models\Rank;
use App\Models\RankPositionMapping;
use App\Models\VesselManning;
use App\Support\MasterData\PrepareRankPositionConsolidation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function makeRankPositionCompany(string $name): Company
{
    $suffix = Str::upper(Str::random(4));

    $country = Country::query()->create([
        'code' => $suffix,
        'name' => "{$name} Country",
        'dial_code' => '+111',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => $suffix,
        'name' => "{$name} Currency",
        'symbol' => '$',
        'is_active' => true,
    ]);

    return Company::query()->create([
        'name' => $name,
        'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

test('exact same title maps correctly including case and whitespace variants', function () {
    $company = makeRankPositionCompany('Exact Map Co');
    $rank = Rank::query()->create([
        'name' => 'Chief Engineer',
        'is_active' => true,
        'max_tour_of_duty_days' => 90,
    ]);
    $position = Position::query()->create([
        'company_id' => $company->id,
        'title' => '  chief   engineer ',
        'status' => 'active',
        'is_crew_position' => false,
        'max_tour_of_duty_days' => null,
    ]);

    $reports = app(PrepareRankPositionConsolidation::class)->run($company->id, true);
    $report = $reports[0];

    expect($report['existing_exact_matches'])->toBe(1)
        ->and($report['new_positions_required'])->toBe(0);

    $mapping = RankPositionMapping::query()
        ->where('company_id', $company->id)
        ->where('rank_id', $rank->id)
        ->sole();

    expect($mapping->position_id)->toBe($position->id)
        ->and($mapping->match_type)->toBe(RankPositionMatchType::Exact);

    $position->refresh();
    expect($position->is_crew_position)->toBeTrue()
        ->and($position->max_tour_of_duty_days)->toBe(90);
});

test('fuzzy and abbreviation matching is not performed', function () {
    $company = makeRankPositionCompany('No Fuzzy Co');
    Rank::query()->create(['name' => 'PTWC', 'is_active' => true]);
    Position::query()->create([
        'company_id' => $company->id,
        'title' => 'PTW Coordinator',
        'status' => 'active',
    ]);

    app(PrepareRankPositionConsolidation::class)->run($company->id, true);

    expect(RankPositionMapping::query()->where('company_id', $company->id)->count())->toBe(1);
    expect(Position::query()->where('company_id', $company->id)->where('title', 'PTWC')->exists())->toBeTrue();
    expect(Position::query()->where('company_id', $company->id)->count())->toBe(2);
});

test('mappings are company isolated and the same rank maps independently per company', function () {
    $companyA = makeRankPositionCompany('Tenant A');
    $companyB = makeRankPositionCompany('Tenant B');
    $rank = Rank::query()->create(['name' => 'Master', 'is_active' => true, 'max_tour_of_duty_days' => 60]);

    Position::query()->create([
        'company_id' => $companyA->id,
        'title' => 'Master',
        'status' => 'active',
        'max_tour_of_duty_days' => null,
    ]);

    app(PrepareRankPositionConsolidation::class)->run(null, true);

    $mapA = RankPositionMapping::query()->where('company_id', $companyA->id)->where('rank_id', $rank->id)->sole();
    $mapB = RankPositionMapping::query()->where('company_id', $companyB->id)->where('rank_id', $rank->id)->sole();

    expect($mapA->match_type)->toBe(RankPositionMatchType::Exact)
        ->and($mapB->match_type)->toBe(RankPositionMatchType::Created)
        ->and($mapA->position_id)->not->toBe($mapB->position_id);

    $positionB = Position::query()->findOrFail($mapB->position_id);
    expect((int) $positionB->company_id)->toBe($companyB->id)
        ->and($positionB->title)->toBe('Master')
        ->and($positionB->is_crew_position)->toBeTrue()
        ->and($positionB->max_tour_of_duty_days)->toBe(60);
});

test('missing position creates a company position with created match type', function () {
    $company = makeRankPositionCompany('Create Position Co');
    $rank = Rank::query()->create([
        'name' => 'Second Engineer',
        'is_active' => true,
        'max_tour_of_duty_days' => 75,
    ]);

    app(PrepareRankPositionConsolidation::class)->run($company->id, true);

    $mapping = RankPositionMapping::query()->where('company_id', $company->id)->where('rank_id', $rank->id)->sole();
    $position = Position::query()->findOrFail($mapping->position_id);

    expect($mapping->match_type)->toBe(RankPositionMatchType::Created)
        ->and($position->company_id)->toBe($company->id)
        ->and($position->department_id)->toBeNull()
        ->and($position->title)->toBe('Second Engineer')
        ->and($position->status)->toBe('active')
        ->and($position->is_crew_position)->toBeTrue()
        ->and($position->max_tour_of_duty_days)->toBe(75);
});

test('command is idempotent and dry-run performs zero writes', function () {
    $company = makeRankPositionCompany('Idempotent Co');
    $rank = Rank::query()->create(['name' => 'Bosun', 'is_active' => true]);

    Artisan::call('master-data:prepare-rank-position-consolidation', [
        '--company' => (string) $company->id,
    ]);

    expect(RankPositionMapping::query()->count())->toBe(0)
        ->and(Position::query()->where('company_id', $company->id)->where('title', 'Bosun')->exists())->toBeFalse();

    Artisan::call('master-data:prepare-rank-position-consolidation', [
        '--company' => (string) $company->id,
        '--apply' => true,
    ]);

    expect(RankPositionMapping::query()->where('company_id', $company->id)->count())->toBe(1);

    Artisan::call('master-data:prepare-rank-position-consolidation', [
        '--company' => (string) $company->id,
        '--apply' => true,
    ]);

    expect(RankPositionMapping::query()->where('company_id', $company->id)->count())->toBe(1)
        ->and(Position::query()->where('company_id', $company->id)->where('title', 'Bosun')->count())->toBe(1);
});

test('employee rank without position is backfilled while conflicts are reported not overwritten', function () {
    $company = makeRankPositionCompany('Employee Map Co');
    $rank = Rank::query()->create(['name' => 'Able Seaman', 'is_active' => true]);
    $mappedPosition = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Able Seaman',
        'status' => 'active',
    ]);
    $otherPosition = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Office Clerk',
        'status' => 'active',
    ]);

    $needsBackfill = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'position_id' => null,
        'status' => 'active',
    ]);
    $consistent = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'position_id' => $mappedPosition->id,
        'status' => 'active',
    ]);
    $conflict = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'position_id' => $otherPosition->id,
        'status' => 'active',
        'employee_no' => 'E-CONFLICT',
        'name' => 'Conflict Person',
    ]);

    $report = app(PrepareRankPositionConsolidation::class)->run($company->id, true)[0];

    expect($needsBackfill->fresh()->position_id)->toBe($mappedPosition->id)
        ->and($consistent->fresh()->position_id)->toBe($mappedPosition->id)
        ->and($conflict->fresh()->position_id)->toBe($otherPosition->id)
        ->and($report['employees_backfilled'])->toBe(1)
        ->and($report['employees_already_consistent'])->toBe(1)
        ->and($report['employee_conflicts'])->toBe(1)
        ->and($report['employee_conflict_details'][0]['employee_id'])->toBe($conflict->id)
        ->and($report['employee_conflict_details'][0]['employee_number'])->toBe('E-CONFLICT');
});

test('crew assignment planning sea service and vessel manning receive position_id without changing history', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $rank->update(['name' => 'Deck Cadet '.Str::random(4), 'max_tour_of_duty_days' => 45]);

    $vessel = makeCrewMovementVessel('Consolidation Vessel', $company);
    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-03-01',
        'planned_leave_date' => '2026-06-01',
    ]);

    $seaService = EmployeeSeaService::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'start_date' => '2025-01-01',
        'end_date' => '2025-06-01',
        'total_days' => 151,
        'total_months' => 5,
        'sort_order' => 1,
    ]);

    $manning = VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 2,
    ]);

    $originalSea = $seaService->only([
        'employee_id',
        'vessel_id',
        'rank_id',
        'start_date',
        'end_date',
        'total_days',
        'total_months',
        'sort_order',
        'crew_assignment_phase_id',
    ]);

    app(PrepareRankPositionConsolidation::class)->run($company->id, true);

    $positionId = RankPositionMapping::query()
        ->where('company_id', $company->id)
        ->where('rank_id', $rank->id)
        ->value('position_id');

    expect($positionId)->not->toBeNull()
        ->and($assignment->fresh()->position_id)->toBe($positionId)
        ->and($assignment->fresh()->rank_id)->toBe($rank->id)
        ->and($planning->fresh()->position_id)->toBe($positionId)
        ->and($seaService->fresh()->position_id)->toBe($positionId)
        ->and($manning->fresh()->position_id)->toBe($positionId);

    expect($seaService->fresh()->only([
        'employee_id',
        'vessel_id',
        'rank_id',
        'start_date',
        'end_date',
        'total_days',
        'total_months',
        'sort_order',
        'crew_assignment_phase_id',
    ]))->toEqual($originalSea);
});

test('document rank requirements copy to position requirements without duplication', function () {
    $company = makeRankPositionCompany('Doc Req Co');
    $rank = Rank::query()->create(['name' => 'Chief Officer', 'is_active' => true]);
    $position = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Chief Officer',
        'status' => 'active',
    ]);
    $documentType = DocumentType::query()->create(['title' => 'STCW', 'is_active' => true]);
    $requirement = DocumentRequirement::factory()
        ->forCompany($company)
        ->forDocumentType($documentType)
        ->create();

    $requirement->ranks()->attach($rank->id);
    $requirement->positions()->attach($position->id);

    $report = app(PrepareRankPositionConsolidation::class)->run($company->id, true)[0];

    expect($report['document_rank_requirements_copied'])->toBe(0)
        ->and(DB::table('document_requirement_position')->where('document_requirement_id', $requirement->id)->count())->toBe(1)
        ->and(DB::table('document_requirement_rank')->where('document_requirement_id', $requirement->id)->count())->toBe(1);

    $otherRank = Rank::query()->create(['name' => 'Third Officer', 'is_active' => true]);
    $requirement->ranks()->attach($otherRank->id);

    $report2 = app(PrepareRankPositionConsolidation::class)->run($company->id, true)[0];
    $createdPositionId = RankPositionMapping::query()
        ->where('company_id', $company->id)
        ->where('rank_id', $otherRank->id)
        ->value('position_id');

    expect($report2['document_rank_requirements_copied'])->toBe(1)
        ->and(DB::table('document_requirement_position')
            ->where('document_requirement_id', $requirement->id)
            ->where('position_id', $createdPositionId)
            ->exists())->toBeTrue();
});

test('one company cannot receive another company position through rank mapping', function () {
    $companyA = makeRankPositionCompany('Isolation A');
    $companyB = makeRankPositionCompany('Isolation B');
    $rank = Rank::query()->create(['name' => 'Port Captain', 'is_active' => true]);

    $foreignPosition = Position::query()->create([
        'company_id' => $companyB->id,
        'title' => 'Port Captain',
        'status' => 'active',
    ]);

    app(PrepareRankPositionConsolidation::class)->run($companyA->id, true);

    $mapping = RankPositionMapping::query()
        ->where('company_id', $companyA->id)
        ->where('rank_id', $rank->id)
        ->sole();

    expect($mapping->position_id)->not->toBe($foreignPosition->id);

    $localPosition = Position::query()->findOrFail($mapping->position_id);
    expect((int) $localPosition->company_id)->toBe($companyA->id);
});

test('ambiguous normalized matches are reported and not auto-selected', function () {
    $company = makeRankPositionCompany('Ambiguous Co');
    $rank = Rank::query()->create(['name' => 'Electrician', 'is_active' => true]);

    Position::query()->create(['company_id' => $company->id, 'title' => 'Electrician', 'status' => 'active']);
    Position::query()->create(['company_id' => $company->id, 'title' => ' electrician ', 'status' => 'inactive']);

    $report = app(PrepareRankPositionConsolidation::class)->run($company->id, true)[0];

    expect($report['ambiguous_normalized_matches'])->toBe(1)
        ->and(RankPositionMapping::query()->where('company_id', $company->id)->where('rank_id', $rank->id)->exists())->toBeFalse();
});

test('tour of duty conflicts keep the existing position value', function () {
    $company = makeRankPositionCompany('TOD Conflict Co');
    $rank = Rank::query()->create([
        'name' => 'Cook',
        'is_active' => true,
        'max_tour_of_duty_days' => 90,
    ]);
    $position = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Cook',
        'status' => 'active',
        'max_tour_of_duty_days' => 60,
    ]);

    $report = app(PrepareRankPositionConsolidation::class)->run($company->id, true)[0];

    expect($report['tod_conflicts'])->toBe(1)
        ->and($position->fresh()->max_tour_of_duty_days)->toBe(60)
        ->and($position->fresh()->is_crew_position)->toBeTrue();
});

test('inactive referenced ranks create inactive positions for historical continuity', function () {
    $company = makeRankPositionCompany('Historical Rank Co');
    $rank = Rank::query()->create([
        'name' => 'Retired Rank',
        'is_active' => false,
    ]);

    Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'position_id' => null,
        'status' => 'active',
    ]);

    app(PrepareRankPositionConsolidation::class)->run($company->id, true);

    $position = Position::query()
        ->where('company_id', $company->id)
        ->where('title', 'Retired Rank')
        ->sole();

    expect($position->status)->toBe('inactive')
        ->and($position->is_crew_position)->toBeTrue();
});
