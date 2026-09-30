<?php

use App\Enums\SavedViewPage;
use App\Models\Position;
use App\Models\Rank;
use App\Models\RankPositionMapping;
use App\Support\CrewMovements\CrewAssignmentPresenter;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\Positions\LegacyRankFilterTranslator;
use App\Support\Positions\RankPositionBridge;
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

test('crew planning relief validation accepts position_id without rank_id in request', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'position' => $position] = makeCrewAssignmentFixtures();

    grantCompanyPermissions($user, $company, ['crew_operations.planning.view', 'crew_operations.planning.create']);

    $vessel = makeCrewMovementVessel('Relief Position Vessel', $company);
    $source = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'position_id' => $position->id,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'position_id' => $position->id,
            'planned_join_date' => now()->addDays(5)->toDateString(),
            'planned_leave_date' => now()->addMonths(2)->toDateString(),
            'relieves_crew_assignment_id' => $source->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

test('crew planning relief validation translates legacy rank_id request to position', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'position' => $position] = makeCrewAssignmentFixtures();

    grantCompanyPermissions($user, $company, ['crew_operations.planning.view', 'crew_operations.planning.create']);

    $vessel = makeCrewMovementVessel('Legacy Rank Relief Vessel', $company);
    $source = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'position_id' => $position->id,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'planned_join_date' => now()->addDays(5)->toDateString(),
            'planned_leave_date' => now()->addMonths(2)->toDateString(),
            'relieves_crew_assignment_id' => $source->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();
});

test('crew planning relief validation rejects unmapped legacy rank safely', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank, 'position' => $position] = makeCrewAssignmentFixtures();

    grantCompanyPermissions($user, $company, ['crew_operations.planning.view', 'crew_operations.planning.create']);

    $orphanRank = Rank::query()->create([
        'name' => 'Unmapped Relief Rank '.uniqid(),
        'is_active' => true,
    ]);

    $vessel = makeCrewMovementVessel('Unmapped Relief Vessel', $company);
    $source = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'position_id' => $position->id,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->from(route('organization.crew-planning.index'))
        ->post(route('organization.crew-planning.assignments.store'), [
            'vessel_id' => $vessel->id,
            'rank_id' => $orphanRank->id,
            'planned_join_date' => now()->addDays(5)->toDateString(),
            'planned_leave_date' => now()->addMonths(2)->toDateString(),
            'relieves_crew_assignment_id' => $source->id,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('position_id');
});

test('current crew index resolves legacy rank filter without static context errors', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank] = makeCrewAssignmentFixtures();

    grantCompanyPermissions($user, $company, ['crew_operations.assignments.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.crew-assignments.index', [
            'rank_id' => $rank->id,
        ]))
        ->assertOk();
});

test('crew assignment presenter never exposes rank id as position id', function () {
    ['company' => $company, 'employee' => $employee, 'rank' => $rank, 'user' => $user] = makeCrewAssignmentFixtures();

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id)->load(['employee', 'rank', 'vessel', 'client', 'currentPhase', 'company']);

    RankPositionBridge::hydrateCanonicalPositions(collect([$assignment]), (int) $company->id);

    $item = CrewAssignmentPresenter::listItem($assignment);

    expect($item['position']['id'])->toBe(
        RankPositionBridge::positionIdForRank((int) $company->id, (int) $rank->id),
    );
});
