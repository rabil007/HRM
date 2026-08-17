<?php

use App\Enums\CrewAssignmentStatus;
use App\Models\Company;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Support\CrewMovements\CrewMovementAttentionQuery;
use App\Support\CrewMovements\CurrentCrewQuery;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, company: Company, employee: Employee, rank: Rank, vessel: Vessel}
 */
function makeMovementAttentionFilterFixtures(): array
{
    $fixtures = makeCrewAssignmentFixtures();
    grantCompanyPermissions($fixtures['user'], $fixtures['company'], [
        'crew_operations.assignments.view',
    ]);
    $fixtures['user']->update(['current_company_id' => $fixtures['company']->id]);

    return [
        ...$fixtures,
        'vessel' => makeCrewMovementVessel('Attention Filter Vessel', $fixtures['company']),
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeDraftAssignmentForAttentionFilter(
    Company $company,
    Rank $rank,
    ?Employee $employee = null,
    ?Vessel $vessel = null,
    array $overrides = [],
): CrewAssignment {
    $employee ??= Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);

    $attributes = array_merge([
        'rank_id' => $rank->id,
        'status' => CrewAssignmentStatus::Draft,
    ], $overrides);

    if ($vessel !== null && ! array_key_exists('vessel_id', $overrides)) {
        $attributes['vessel_id'] = $vessel->id;
    }

    return CrewAssignment::factory()
        ->forEmployee($employee)
        ->draft()
        ->create($attributes);
}

function makeStaleDraftAssignmentForAttentionFilter(
    Company $company,
    Rank $rank,
    ?Employee $employee = null,
    ?Vessel $vessel = null,
    array $overrides = [],
): CrewAssignment {
    return makeDraftAssignmentForAttentionFilter($company, $rank, $employee, $vessel, array_merge([
        'created_at' => now()->subDays(10),
        'updated_at' => now()->subDays(10),
    ], $overrides));
}

test('needs attention filter returns assignments that would have been past the first unfiltered page', function () {
    [
        'user' => $user,
        'company' => $company,
        'rank' => $rank,
        'vessel' => $vessel,
    ] = makeMovementAttentionFilterFixtures();

    $staleA = makeStaleDraftAssignmentForAttentionFilter($company, $rank, null, $vessel, [
        'assignment_no' => 'CA-ATTN-A',
        'created_at' => now()->subDays(12),
        'updated_at' => now()->subDays(12),
    ]);
    $staleB = makeStaleDraftAssignmentForAttentionFilter($company, $rank, null, $vessel, [
        'assignment_no' => 'CA-ATTN-B',
        'created_at' => now()->subDays(11),
        'updated_at' => now()->subDays(11),
    ]);

    foreach (range(1, 20) as $index) {
        makeDraftAssignmentForAttentionFilter($company, $rank, null, $vessel, [
            'assignment_no' => 'CA-FRESH-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
        ]);
    }

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', [
            'per_page' => 15,
            'page' => 1,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignments', 15)
            ->where('pagination.total', 22)
            ->where('summary.needs_attention', 2));

    $unfilteredIds = collect($this->actingAs($user)
        ->get(route('organization.crew-assignments.index', [
            'per_page' => 15,
            'page' => 1,
        ]))
        ->inertiaProps('assignments'))->pluck('id')->all();

    expect($unfilteredIds)
        ->not->toContain($staleA->id)
        ->and($unfilteredIds)->not->toContain($staleB->id);

    $response = $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', [
            'movement_attention' => 1,
            'per_page' => 15,
            'page' => 1,
        ]));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew/index')
            ->has('assignments', 2)
            ->where('pagination.total', 2)
            ->where('pagination.current_page', 1)
            ->where('pagination.last_page', 1)
            ->where('pagination.per_page', 15)
            ->where('summary.needs_attention', 2)
            ->where('filters.movement_attention', true));

    $filteredIds = collect($response->inertiaProps('assignments'))->pluck('id')->all();

    expect($filteredIds)->toEqualCanonicalizing([$staleA->id, $staleB->id])
        ->and(CrewMovementAttentionQuery::summaryCounts($company->id)['needs_attention'])->toBe(2);
});

test('needs attention results paginate across multiple pages with accurate totals', function () {
    [
        'user' => $user,
        'company' => $company,
        'rank' => $rank,
        'vessel' => $vessel,
    ] = makeMovementAttentionFilterFixtures();

    foreach (range(1, 17) as $index) {
        makeStaleDraftAssignmentForAttentionFilter($company, $rank, null, $vessel, [
            'assignment_no' => 'CA-ATTN-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            'created_at' => now()->subDays(20)->addMinutes($index),
            'updated_at' => now()->subDays(20)->addMinutes($index),
        ]);
    }

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', [
            'movement_attention' => 1,
            'per_page' => 15,
            'page' => 1,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignments', 15)
            ->where('pagination.total', 17)
            ->where('pagination.current_page', 1)
            ->where('pagination.last_page', 2)
            ->where('pagination.per_page', 15)
            ->where('summary.needs_attention', 17));

    $pageTwo = $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', [
            'movement_attention' => 1,
            'per_page' => 15,
            'page' => 2,
        ]));

    $pageTwo->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignments', 2)
            ->where('pagination.total', 17)
            ->where('pagination.current_page', 2)
            ->where('pagination.last_page', 2)
            ->where('summary.needs_attention', 17));

    $pageOneIds = collect($this->actingAs($user)
        ->get(route('organization.crew-assignments.index', [
            'movement_attention' => 1,
            'per_page' => 15,
            'page' => 1,
        ]))
        ->inertiaProps('assignments'))->pluck('id');
    $pageTwoIds = collect($pageTwo->inertiaProps('assignments'))->pluck('id');

    expect($pageOneIds->intersect($pageTwoIds)->all())->toBe([])
        ->and($pageOneIds->merge($pageTwoIds)->unique()->count())->toBe(17);
});

test('needs attention combined with vessel filter returns the intersection', function () {
    [
        'user' => $user,
        'company' => $company,
        'rank' => $rank,
        'vessel' => $vessel,
    ] = makeMovementAttentionFilterFixtures();

    $otherVessel = makeCrewMovementVessel('Other Attention Vessel', $company);

    $onVessel = makeStaleDraftAssignmentForAttentionFilter($company, $rank, null, $vessel, [
        'assignment_no' => 'CA-ATTN-VESSEL',
    ]);
    makeStaleDraftAssignmentForAttentionFilter($company, $rank, null, $otherVessel, [
        'assignment_no' => 'CA-ATTN-OTHER',
    ]);
    makeDraftAssignmentForAttentionFilter($company, $rank, null, $vessel, [
        'assignment_no' => 'CA-FRESH-VESSEL',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', [
            'movement_attention' => 1,
            'vessel_id' => $vessel->id,
            'per_page' => 15,
            'page' => 1,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignments', 1)
            ->where('assignments.0.id', $onVessel->id)
            ->where('pagination.total', 1)
            ->where('pagination.last_page', 1)
            ->where('summary.needs_attention', 2));
});

test('inactive employee draft with attention warnings stays hidden from current crew', function () {
    [
        'user' => $user,
        'company' => $company,
        'rank' => $rank,
        'vessel' => $vessel,
    ] = makeMovementAttentionFilterFixtures();

    $visible = makeStaleDraftAssignmentForAttentionFilter($company, $rank, null, $vessel, [
        'assignment_no' => 'CA-ATTN-ACTIVE',
    ]);

    $inactive = Employee::factory()->forCompany($company)->inactive()->create([
        'rank_id' => $rank->id,
    ]);
    makeStaleDraftAssignmentForAttentionFilter($company, $rank, $inactive, $vessel, [
        'assignment_no' => 'CA-ATTN-INACTIVE',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', [
            'movement_attention' => 1,
            'per_page' => 15,
            'page' => 1,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignments', 1)
            ->where('assignments.0.id', $visible->id)
            ->where('pagination.total', 1)
            ->where('summary.needs_attention', 1)
            ->where('summary.total', 1));
});

test('needs attention filter and summary stay isolated to the current company', function () {
    [
        'user' => $user,
        'company' => $company,
        'rank' => $rank,
        'vessel' => $vessel,
    ] = makeMovementAttentionFilterFixtures();

    $own = makeStaleDraftAssignmentForAttentionFilter($company, $rank, null, $vessel, [
        'assignment_no' => 'CA-ATTN-OWN',
    ]);

    $foreign = makeCrewAssignmentFixtures();
    makeStaleDraftAssignmentForAttentionFilter(
        $foreign['company'],
        $foreign['rank'],
        $foreign['employee'],
        makeCrewMovementVessel('Foreign Attention Vessel', $foreign['company']),
        ['assignment_no' => 'CA-ATTN-FOREIGN'],
    );

    $this->actingAs($user)
        ->get(route('organization.crew-assignments.index', [
            'movement_attention' => 1,
            'per_page' => 15,
            'page' => 1,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignments', 1)
            ->where('assignments.0.id', $own->id)
            ->where('pagination.total', 1)
            ->where('summary.needs_attention', 1)
            ->where('summary.total', 1));

    expect(CrewMovementAttentionQuery::summaryCounts($foreign['company']->id)['needs_attention'])->toBe(1)
        ->and(CrewMovementAttentionQuery::summaryCounts($company->id)['needs_attention'])->toBe(1);
});

it('keeps needs attention filtering query count bounded as operational assignments grow', function () {
    [
        'company' => $company,
        'rank' => $rank,
        'vessel' => $vessel,
    ] = makeMovementAttentionFilterFixtures();

    makeStaleDraftAssignmentForAttentionFilter($company, $rank, null, $vessel);
    makeDraftAssignmentForAttentionFilter($company, $rank, null, $vessel);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $small = CurrentCrewQuery::paginate($company->id, [
        'movement_attention' => '1',
        'per_page' => 15,
    ]);
    $smallCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($small->total())->toBe(1)
        ->and($small->count())->toBe(1);

    foreach (range(1, 18) as $index) {
        makeDraftAssignmentForAttentionFilter($company, $rank, null, $vessel, [
            'assignment_no' => 'CA-SCALE-'.$index,
        ]);
    }

    makeStaleDraftAssignmentForAttentionFilter($company, $rank, null, $vessel, [
        'assignment_no' => 'CA-SCALE-ATTN',
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $large = CurrentCrewQuery::paginate($company->id, [
        'movement_attention' => '1',
        'per_page' => 15,
    ]);
    $largeCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($large->total())->toBe(2)
        ->and($large->count())->toBe(2)
        ->and($largeCount)->toBeLessThanOrEqual($smallCount + 8)
        ->and($largeCount - $smallCount)->toBeLessThan(18);
});
