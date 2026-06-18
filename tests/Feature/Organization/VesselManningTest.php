<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselManning;
use App\Models\VesselType;
use Inertia\Testing\AssertableInertia as Assert;

function makeVesselManningFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'VMN',
        'name' => 'Vessel Manning Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'VMN',
        'name' => 'Vessel Manning Currency',
        'symbol' => 'V$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Vessel Manning Co',
        'slug' => 'vessel-manning-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Manning Co',
        'slug' => 'other-manning-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'AHTS',
        'is_active' => true,
    ]);

    $vessel = Vessel::query()->create([
        'name' => 'Vessel Alpha',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $inactiveVessel = Vessel::query()->create([
        'name' => 'Inactive Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => false,
    ]);

    $captain = Rank::query()->create([
        'name' => 'Captain',
        'is_active' => true,
    ]);

    $welder = Rank::query()->create([
        'name' => 'Welder',
        'is_active' => true,
    ]);

    $inactiveRank = Rank::query()->create([
        'name' => 'Inactive Rank',
        'is_active' => false,
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.vessel_manning.view',
        'crew_operations.vessel_manning.create',
        'crew_operations.vessel_manning.update',
        'crew_operations.vessel_manning.delete',
    ]);

    return compact(
        'user',
        'company',
        'otherCompany',
        'vesselType',
        'vessel',
        'inactiveVessel',
        'captain',
        'welder',
        'inactiveRank',
    );
}

test('guests cannot access vessel manning', function () {
    $this->get(route('organization.vessel-manning.index'))
        ->assertRedirect(route('login'));
});

test('authorized users can view vessel manning index', function () {
    [
        'user' => $user,
        'vessel' => $vessel,
        'company' => $company,
        'captain' => $captain,
        'welder' => $welder,
    ] = makeVesselManningFixtures();

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 1,
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $welder->id,
        'required_count' => 2,
    ]);

    $this->actingAs($user)
        ->get(route('organization.vessel-manning.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/vessel-manning/index')
            ->has('vessels', 2)
            ->where('can.create', true)
            ->where('can.update', true)
            ->where('can.delete', true)
            ->where('vessels.0.name', 'Inactive Vessel')
            ->where('vessels.1.name', 'Vessel Alpha')
            ->where('vessels.1.total_required', 3)
            ->has('vessels.1.manning', 2)
        );
});

test('users without view permission cannot access vessel manning index', function () {
    ['user' => $user, 'company' => $company] = makeVesselManningFixtures();

    grantCompanyPermissions($user, $company, []);

    $this->actingAs($user)
        ->get(route('organization.vessel-manning.index'))
        ->assertForbidden();
});

test('authorized users can sync vessel manning requirements', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
        'welder' => $welder,
    ] = makeVesselManningFixtures();

    $this->actingAs($user)
        ->from(route('organization.vessel-manning.index'))
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [
                ['rank_id' => $captain->id, 'required_count' => 1],
                ['rank_id' => $welder->id, 'required_count' => 2],
            ],
        ])
        ->assertRedirect(route('organization.vessel-manning.index'))
        ->assertSessionHas('success');

    $this->assertDatabaseHas('vessel_manning', [
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 1,
    ]);

    $this->assertDatabaseHas('vessel_manning', [
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $welder->id,
        'required_count' => 2,
    ]);
});

test('sync updates existing rows and removes missing ranks', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
        'welder' => $welder,
    ] = makeVesselManningFixtures();

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 1,
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $welder->id,
        'required_count' => 2,
    ]);

    $this->actingAs($user)
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [
                ['rank_id' => $welder->id, 'required_count' => 4],
            ],
        ])
        ->assertRedirect(route('organization.vessel-manning.index'));

    $this->assertSoftDeleted('vessel_manning', [
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
    ]);

    $this->assertDatabaseHas('vessel_manning', [
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $welder->id,
        'required_count' => 4,
    ]);
});

test('sync can clear all requirements', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
    ] = makeVesselManningFixtures();

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 1,
    ]);

    $this->actingAs($user)
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [],
        ])
        ->assertRedirect(route('organization.vessel-manning.index'));

    expect(VesselManning::query()
        ->where('company_id', $company->id)
        ->where('vessel_id', $vessel->id)
        ->count())->toBe(0);
});

test('vessel manning is scoped per company', function () {
    [
        'user' => $user,
        'company' => $company,
        'otherCompany' => $otherCompany,
        'vessel' => $vessel,
        'captain' => $captain,
    ] = makeVesselManningFixtures();

    VesselManning::query()->create([
        'company_id' => $otherCompany->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 5,
    ]);

    $this->actingAs($user)
        ->get(route('organization.vessel-manning.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('vessels.1.total_required', 0)
        );

    $this->actingAs($user)
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [
                ['rank_id' => $captain->id, 'required_count' => 1],
            ],
        ])
        ->assertRedirect(route('organization.vessel-manning.index'));

    $this->assertDatabaseHas('vessel_manning', [
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 1,
    ]);

    $this->assertDatabaseHas('vessel_manning', [
        'company_id' => $otherCompany->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 5,
    ]);
});

test('users without update permission cannot modify existing vessel manning', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
        'welder' => $welder,
    ] = makeVesselManningFixtures();

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 1,
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.vessel_manning.view',
        'crew_operations.vessel_manning.create',
        'crew_operations.vessel_manning.delete',
    ]);

    $this->actingAs($user)
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [
                ['rank_id' => $welder->id, 'required_count' => 2],
            ],
        ])
        ->assertForbidden();
});

test('users without create permission cannot add first vessel manning', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'captain' => $captain] = makeVesselManningFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.vessel_manning.view',
        'crew_operations.vessel_manning.update',
        'crew_operations.vessel_manning.delete',
    ]);

    $this->actingAs($user)
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [
                ['rank_id' => $captain->id, 'required_count' => 1],
            ],
        ])
        ->assertForbidden();
});

test('users without delete permission cannot clear vessel manning', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
    ] = makeVesselManningFixtures();

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $captain->id,
        'required_count' => 1,
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.vessel_manning.view',
        'crew_operations.vessel_manning.create',
        'crew_operations.vessel_manning.update',
    ]);

    $this->actingAs($user)
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [],
        ])
        ->assertForbidden();
});

test('users without manage permission cannot update vessel manning', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
    ] = makeVesselManningFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.vessel_manning.view',
    ]);

    $this->actingAs($user)
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [
                ['rank_id' => $captain->id, 'required_count' => 1],
            ],
        ])
        ->assertForbidden();
});

test('duplicate ranks are rejected when syncing vessel manning', function () {
    [
        'user' => $user,
        'vessel' => $vessel,
        'captain' => $captain,
    ] = makeVesselManningFixtures();

    $this->actingAs($user)
        ->from(route('organization.vessel-manning.index'))
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [
                ['rank_id' => $captain->id, 'required_count' => 1],
                ['rank_id' => $captain->id, 'required_count' => 2],
            ],
        ])
        ->assertRedirect(route('organization.vessel-manning.index'))
        ->assertSessionHasErrors('requirements.1.rank_id');
});

test('inactive ranks are rejected when syncing vessel manning', function () {
    [
        'user' => $user,
        'vessel' => $vessel,
        'inactiveRank' => $inactiveRank,
    ] = makeVesselManningFixtures();

    $this->actingAs($user)
        ->from(route('organization.vessel-manning.index'))
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [
                ['rank_id' => $inactiveRank->id, 'required_count' => 1],
            ],
        ])
        ->assertRedirect(route('organization.vessel-manning.index'))
        ->assertSessionHasErrors('requirements.0.rank_id');
});

test('inactive vessels cannot be updated', function () {
    [
        'user' => $user,
        'inactiveVessel' => $inactiveVessel,
        'captain' => $captain,
    ] = makeVesselManningFixtures();

    $this->actingAs($user)
        ->from(route('organization.vessel-manning.index'))
        ->put(route('organization.vessel-manning.update', $inactiveVessel), [
            'requirements' => [
                ['rank_id' => $captain->id, 'required_count' => 1],
            ],
        ])
        ->assertRedirect(route('organization.vessel-manning.index'))
        ->assertSessionHasErrors('vessel');
});

test('required count must be at least one', function () {
    [
        'user' => $user,
        'vessel' => $vessel,
        'captain' => $captain,
    ] = makeVesselManningFixtures();

    $this->actingAs($user)
        ->from(route('organization.vessel-manning.index'))
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [
                ['rank_id' => $captain->id, 'required_count' => 0],
            ],
        ])
        ->assertRedirect(route('organization.vessel-manning.index'))
        ->assertSessionHasErrors('requirements.0.required_count');
});
