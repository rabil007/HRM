<?php

use App\Enums\SavedViewPage;
use App\Models\Position;
use App\Models\Rank;
use App\Models\RankPositionMapping;
use App\Support\Positions\LegacyRankFilterTranslator;
use App\Support\SavedViews\SavedViewCatalog;

test('legacy rank_id query translates to tenant position_id', function () {
    ['company' => $company, 'rank' => $rank, 'position' => $position] = makeCrewAssignmentFixtures();

    $resolved = LegacyRankFilterTranslator::resolvePositionId(
        (int) $company->id,
        null,
        $rank->id,
    );

    expect($resolved)->toBe((int) $position->id);
});

test('legacy rank_id cannot map across companies', function () {
    ['company' => $companyA] = makeCrewAssignmentFixtures();
    ['company' => $companyB] = makeCrewAssignmentFixtures();

    $orphanRank = Rank::query()->create([
        'name' => 'Orphan Cross Rank '.uniqid(),
        'is_active' => true,
    ]);

    $foreignPosition = Position::query()->create([
        'company_id' => $companyB->id,
        'title' => 'Foreign Only',
        'is_crew_position' => true,
        'status' => 'active',
    ]);

    RankPositionMapping::query()->create([
        'company_id' => $companyB->id,
        'rank_id' => $orphanRank->id,
        'position_id' => $foreignPosition->id,
        'match_type' => 'exact',
    ]);

    expect(LegacyRankFilterTranslator::resolvePositionId(
        (int) $companyA->id,
        null,
        $orphanRank->id,
    ))->toBeNull();
});

test('saved crew views migrate legacy rank_id to position_id on save', function () {
    ['company' => $company, 'rank' => $rank, 'position' => $position] = makeCrewAssignmentFixtures();

    $normalized = SavedViewCatalog::normalizeForSave(
        SavedViewPage::Crew,
        ['rank_id' => (string) $rank->id, 'vessel_id' => ''],
        (int) $company->id,
    );

    expect($normalized)->toHaveKey('position_id')
        ->and($normalized['position_id'])->toBe((string) $position->id)
        ->and($normalized)->not->toHaveKey('rank_id');
});

test('crew movement history accepts legacy rank_id filter in query string', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank, 'position' => $position] = makeCrewAssignmentFixtures();

    grantCompanyPermissions($user, $company, ['reports.crew_movement_history.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.reports.crew-movement-history.index', [
            'rank_id' => $rank->id,
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.position_id', (string) $position->id));
});
