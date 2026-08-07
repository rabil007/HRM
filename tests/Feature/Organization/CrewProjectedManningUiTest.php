<?php

use App\Models\Company;
use App\Models\Rank;
use App\Models\User;
use App\Models\VesselManning;
use App\Support\CrewOperations\CrewProjectedManningQuery;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

function makeProjectedManningUiFixtures(array $permissions = [
    'crew_operations.vessel_manning.view',
    'crew_operations.planning.view',
    'crew_operations.planning.create',
]): array
{
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $vessel = makeCrewMovementVessel('Projected UI Vessel');

    grantCompanyPermissions($user, $company, $permissions);

    $user->update(['current_company_id' => $company->id]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 1,
    ]);

    makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'planned_signoff_at' => CarbonImmutable::now(CompanyTimezone::forCompanyId((int) $company->id))
            ->addDays(20)
            ->startOfDay()
            ->toDateTimeString(),
    ]);

    return compact('user', 'company', 'employee', 'rank', 'vessel');
}

it('redirects guests away from projected manning', function () {
    $this->get(route('organization.crew-operations.projected-manning'))
        ->assertRedirect(route('login'));
});

it('forbids users without vessel manning view permission', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('organization.crew-operations.projected-manning'))
        ->assertForbidden();
});

it('renders projected manning with default 30-day horizon from company today', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'rank' => $rank] = makeProjectedManningUiFixtures();

    $timezone = CompanyTimezone::forCompanyId((int) $company->id);
    $from = CarbonImmutable::now($timezone)->toDateString();
    $to = CarbonImmutable::parse($from, $timezone)->addDays(30)->toDateString();

    $expected = (new CrewProjectedManningQuery)->forCompany(
        (int) $company->id,
        $from,
        $to,
    );

    $this->actingAs($user)
        ->get(route('organization.crew-operations.projected-manning'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-operations/projected-manning')
            ->where('from', $from)
            ->where('to', $to)
            ->where('filters.horizon', 30)
            ->where('filters.vessel_id', null)
            ->where('filters.rank_id', null)
            ->where('summary.positions', $expected['summary']['positions'])
            ->where('summary.current_gap_positions', $expected['summary']['current_gap_positions'])
            ->where('items.0.vessel_id', $vessel->id)
            ->where('items.0.rank_id', $rank->id)
            ->where('items.0.actual_onboard_at_start', $expected['items'][0]['actual_onboard_at_start'])
            ->where('items.0.projected_count_at_start', $expected['items'][0]['projected_count_at_start'])
            ->where('items.0.status', $expected['items'][0]['status'])
            ->where('can.view', true)
            ->where('can.plan_crew', true)
            ->has('horizons', 3)
            ->has('vessels', 1)
            ->has('ranks', 1)
        );
});

it('sets plan_crew true when user can view and create planning', function () {
    ['user' => $user] = makeProjectedManningUiFixtures([
        'crew_operations.vessel_manning.view',
        'crew_operations.planning.view',
        'crew_operations.planning.create',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.projected-manning'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.plan_crew', true)
        );
});

it('sets plan_crew false when user can only view planning', function () {
    ['user' => $user] = makeProjectedManningUiFixtures([
        'crew_operations.vessel_manning.view',
        'crew_operations.planning.view',
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.projected-manning'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.view', true)
            ->where('can.plan_crew', false)
        );
});

it('supports 30 60 and 90 day horizons', function (int $horizon) {
    ['user' => $user, 'company' => $company] = makeProjectedManningUiFixtures();

    $timezone = CompanyTimezone::forCompanyId((int) $company->id);
    $from = CarbonImmutable::now($timezone)->toDateString();
    $to = CarbonImmutable::parse($from, $timezone)->addDays($horizon)->toDateString();

    $this->actingAs($user)
        ->get(route('organization.crew-operations.projected-manning', ['horizon' => $horizon]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.horizon', $horizon)
            ->where('from', $from)
            ->where('to', $to)
        );
})->with([30, 60, 90]);

it('filters projection by vessel and rank', function () {
    ['user' => $user, 'company' => $company, 'rank' => $rank, 'vessel' => $vessel] = makeProjectedManningUiFixtures();

    $otherVessel = makeCrewMovementVessel('Other Projected Vessel');
    $otherRank = Rank::query()->create([
        'name' => 'Other Projected Rank '.uniqid(),
        'is_active' => true,
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $otherVessel->id,
        'rank_id' => $otherRank->id,
        'required_count' => 2,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.projected-manning', [
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.vessel_id', $vessel->id)
            ->where('filters.rank_id', $rank->id)
            ->has('items', 1)
            ->where('items.0.vessel_id', $vessel->id)
            ->where('items.0.rank_id', $rank->id)
            ->where('summary.positions', 1)
        );
});

it('rejects invalid horizon values', function () {
    ['user' => $user] = makeProjectedManningUiFixtures();

    $this->actingAs($user)
        ->get(route('organization.crew-operations.projected-manning', ['horizon' => 45]))
        ->assertSessionHasErrors('horizon');
});

it('rejects vessel and rank filters that are not valid for the active company', function () {
    ['user' => $user, 'company' => $companyA, 'rank' => $rankA, 'vessel' => $vesselA] = makeProjectedManningUiFixtures();

    $companyB = Company::query()->create([
        'name' => 'Projected Other Co',
        'slug' => 'projected-other-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $companyA->country_id,
        'currency_id' => $companyA->currency_id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $vesselB = makeCrewMovementVessel('Foreign Projected Vessel');
    $rankB = Rank::query()->create([
        'name' => 'Foreign Projected Rank '.uniqid(),
        'is_active' => true,
    ]);

    VesselManning::query()->create([
        'company_id' => $companyB->id,
        'vessel_id' => $vesselB->id,
        'rank_id' => $rankB->id,
        'required_count' => 5,
    ]);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.projected-manning', [
            'vessel_id' => $vesselB->id,
            'rank_id' => $rankB->id,
        ]))
        ->assertSessionHasErrors(['vessel_id', 'rank_id']);

    $this->actingAs($user)
        ->get(route('organization.crew-operations.projected-manning'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('items', 1)
            ->where('items.0.vessel_id', $vesselA->id)
            ->where('items.0.rank_id', $rankA->id)
            ->where('summary.positions', 1)
        );
});

it('opens crew planning create prefill from projected manning plan crew params', function () {
    ['user' => $user, 'vessel' => $vessel, 'rank' => $rank] = makeProjectedManningUiFixtures();

    $nextGapDate = CarbonImmutable::now(CompanyTimezone::forCompanyId(
        (int) $user->current_company_id,
    ))->addDays(10)->toDateString();

    $from = CarbonImmutable::now(CompanyTimezone::forCompanyId(
        (int) $user->current_company_id,
    ))->toDateString();
    $to = CarbonImmutable::parse($from)->addDays(30)->toDateString();

    $this->actingAs($user)
        ->get(route('organization.crew-planning.index', [
            'open_create' => 1,
            'vessel_id' => $vessel->id,
            'rank_id' => $rank->id,
            'from' => $from,
            'to' => $to,
            'planned_join_date' => $nextGapDate,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/crew-planning/index')
            ->where('relief_prefill.open_create', true)
            ->where('relief_prefill.vessel_id', $vessel->id)
            ->where('relief_prefill.rank_id', $rank->id)
            ->where('relief_prefill.planned_join_date', $nextGapDate)
            ->where('filters.vessel_id', $vessel->id)
            ->where('filters.rank_id', $rank->id)
        );
});
