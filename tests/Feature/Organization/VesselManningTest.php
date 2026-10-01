<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Position;
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
        'company_id' => $company->id,
        'name' => 'Vessel Alpha',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $inactiveVessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'Inactive Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => false,
    ]);

    $captain = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Captain',
        'status' => 'active', 'is_crew_position' => true,
    ]);

    $welder = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Welder',
        'status' => 'active', 'is_crew_position' => true,
    ]);

    $inactiveRank = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Inactive Rank',
        'status' => 'inactive', 'is_crew_position' => true,
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.vessels.view',
        'crew_operations.vessel_manning.view',
        'crew_operations.vessel_manning.create',
        'crew_operations.vessel_manning.update',
        'crew_operations.vessel_manning.delete',
    ]);

    $captainPosition = crewPositionForRank($company, $captain);
    $welderPosition = crewPositionForRank($company, $welder);

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
        'captainPosition',
        'welderPosition',
    );
}

test('vessel manning show redirects to organization vessels show preserving query', function () {
    ['user' => $user, 'vessel' => $vessel] = makeVesselManningFixtures();

    $this->actingAs($user)
        ->get(route('organization.vessel-manning.show', [
            'vessel' => $vessel,
            'search' => 'Alpha',
            'page' => 2,
        ]))
        ->assertRedirect(route('organization.vessels.show', [
            'vessel' => $vessel,
            'search' => 'Alpha',
            'page' => 2,
        ]));
});

test('authorized users can view vessel manning on vessels show page', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
        'welder' => $welder,
        'captainPosition' => $captainPosition,
        'welderPosition' => $welderPosition,
    ] = makeVesselManningFixtures();

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $captainPosition->id,
        'position_id' => $captain->id,
        'required_count' => 1,
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $welderPosition->id,
        'position_id' => $welder->id,
        'required_count' => 2,
    ]);

    $this->actingAs($user)
        ->get(route('organization.vessels.show', [
            'vessel' => $vessel,
            'search' => 'Alpha',
            'page' => 2,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/vessels/show', false)
            ->where('vessel.name', 'Vessel Alpha')
            ->where('vessel.total_required', 3)
            ->where('vessel.ranks_configured', 2)
            ->where('back_query.search', 'Alpha')
            ->where('back_query.page', '2')
            ->has('vessel.manning', 2)
            ->has('crew_positions')
            ->has('manning_can')
        );
});

test('updating from show page returns to vessels show page', function () {
    ['user' => $user, 'vessel' => $vessel, 'captain' => $captain, 'welder' => $welder, 'captainPosition' => $captainPosition, 'welderPosition' => $welderPosition] = makeVesselManningFixtures();

    $this->actingAs($user)
        ->from(route('organization.vessels.show', ['vessel' => $vessel, 'search' => 'Alpha']))
        ->put(route('organization.vessel-manning.update', ['vessel' => $vessel, 'search' => 'Alpha']), [
            'requirements' => [
                ['position_id' => $captainPosition->id, 'required_count' => 1],
                ['position_id' => $welderPosition->id, 'required_count' => 3],
            ],
            'redirect_to' => 'show',
        ])
        ->assertRedirect(route('organization.vessels.show', [
            'vessel' => $vessel,
            'search' => 'Alpha',
        ]));
});

test('vessels manning update route syncs requirements and redirects to vessels show', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'captain' => $captain, 'captainPosition' => $captainPosition] = makeVesselManningFixtures();

    $this->actingAs($user)
        ->put(route('organization.vessels.manning.update', ['vessel' => $vessel, 'search' => 'Alpha']), [
            'requirements' => [
                ['position_id' => $captainPosition->id, 'required_count' => 2],
            ],
            'redirect_to' => 'show',
        ])
        ->assertRedirect(route('organization.vessels.show', [
            'vessel' => $vessel,
            'search' => 'Alpha',
        ]));

    $this->assertDatabaseHas('vessel_manning', [
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $captain->id,
        'required_count' => 2,
    ]);
});

test('guests cannot access vessel manning show page', function () {
    ['vessel' => $vessel] = makeVesselManningFixtures();

    $this->get(route('organization.vessel-manning.show', $vessel))
        ->assertRedirect(route('login'));
});

test('guests cannot access vessel manning', function () {
    $this->get(route('organization.vessel-manning.index'))
        ->assertRedirect(route('login'));
});

test('vessel manning index redirects to organization vessels index', function () {
    ['user' => $user] = makeVesselManningFixtures();

    $this->actingAs($user)
        ->get(route('organization.vessel-manning.index', [
            'search' => 'Alpha',
            'page' => 2,
        ]))
        ->assertRedirect(route('organization.vessels.index', [
            'search' => 'Alpha',
            'page' => 2,
        ]));
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
        'captainPosition' => $captainPosition,
        'welderPosition' => $welderPosition,
    ] = makeVesselManningFixtures();

    $this->actingAs($user)
        ->from(route('organization.vessels.index'))
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [
                ['position_id' => $captainPosition->id, 'required_count' => 1],
                ['position_id' => $welderPosition->id, 'required_count' => 2],
            ],
        ])
        ->assertRedirect(route('organization.vessels.index'))
        ->assertSessionHas('success');

    $this->assertDatabaseHas('vessel_manning', [
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $captain->id,
        'required_count' => 1,
    ]);

    $this->assertDatabaseHas('vessel_manning', [
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $welder->id,
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
        'captainPosition' => $captainPosition,
        'welderPosition' => $welderPosition,
    ] = makeVesselManningFixtures();

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $captainPosition->id,
        'position_id' => $captain->id,
        'required_count' => 1,
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $welderPosition->id,
        'position_id' => $welder->id,
        'required_count' => 2,
    ]);

    $this->actingAs($user)
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [
                ['position_id' => $welderPosition->id, 'required_count' => 4],
            ],
        ])
        ->assertRedirect(route('organization.vessels.index'));

    $this->assertSoftDeleted('vessel_manning', [
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $captain->id,
    ]);

    $this->assertDatabaseHas('vessel_manning', [
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $welder->id,
        'required_count' => 4,
    ]);
});

test('sync can clear all requirements', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
        'captainPosition' => $captainPosition,
    ] = makeVesselManningFixtures();

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $captainPosition->id,
        'position_id' => $captain->id,
        'required_count' => 1,
    ]);

    $this->actingAs($user)
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [],
        ])
        ->assertRedirect(route('organization.vessels.index'));

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
        'captainPosition' => $captainPosition,
    ] = makeVesselManningFixtures();

    VesselManning::query()->create([
        'company_id' => $otherCompany->id,
        'vessel_id' => $vessel->id,
        'position_id' => $captain->id,
        'required_count' => 5,
    ]);

    $this->actingAs($user)
        ->get(route('organization.vessels.show', $vessel))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('vessel.total_required', 0)
            ->where('vessel.ranks_configured', 0)
        );

    $this->actingAs($user)
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [
                ['position_id' => $captainPosition->id, 'required_count' => 1],
            ],
        ])
        ->assertRedirect(route('organization.vessels.index'));

    $this->assertDatabaseHas('vessel_manning', [
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $captain->id,
        'required_count' => 1,
    ]);

    $this->assertDatabaseHas('vessel_manning', [
        'company_id' => $otherCompany->id,
        'vessel_id' => $vessel->id,
        'position_id' => $captain->id,
        'required_count' => 5,
    ]);
});

test('syncing manning rejects vessels from another company', function () {
    [
        'user' => $user,
        'otherCompany' => $otherCompany,
        'vesselType' => $vesselType,
        'captain' => $captain,
        'captainPosition' => $captainPosition,
    ] = makeVesselManningFixtures();

    $foreignVessel = Vessel::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Foreign Manning Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $this->actingAs($user)
        ->put(route('organization.vessel-manning.update', $foreignVessel), [
            'requirements' => [
                ['position_id' => $captainPosition->id, 'required_count' => 1],
            ],
        ])
        ->assertForbidden();
});

test('users without update permission cannot modify existing vessel manning', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
        'captain' => $captain,
        'welder' => $welder,
        'captainPosition' => $captainPosition,
        'welderPosition' => $welderPosition,
    ] = makeVesselManningFixtures();

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $captainPosition->id,
        'position_id' => $captain->id,
        'required_count' => 1,
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.vessels.view',
        'crew_operations.vessel_manning.view',
        'crew_operations.vessel_manning.create',
        'crew_operations.vessel_manning.delete',
    ]);

    $this->actingAs($user)
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [
                ['position_id' => $welderPosition->id, 'required_count' => 2],
            ],
        ])
        ->assertForbidden();
});

test('users without create permission cannot add first vessel manning', function () {
    ['user' => $user, 'company' => $company, 'vessel' => $vessel, 'captain' => $captain, 'captainPosition' => $captainPosition] = makeVesselManningFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.vessels.view',
        'crew_operations.vessel_manning.view',
        'crew_operations.vessel_manning.update',
        'crew_operations.vessel_manning.delete',
    ]);

    $this->actingAs($user)
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [
                ['position_id' => $captainPosition->id, 'required_count' => 1],
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
        'captainPosition' => $captainPosition,
    ] = makeVesselManningFixtures();

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'position_id' => $captainPosition->id,
        'position_id' => $captain->id,
        'required_count' => 1,
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.vessels.view',
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
        'captainPosition' => $captainPosition,
    ] = makeVesselManningFixtures();

    grantCompanyPermissions($user, $company, [
        'crew_operations.vessels.view',
        'crew_operations.vessel_manning.view',
    ]);

    $this->actingAs($user)
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [
                ['position_id' => $captainPosition->id, 'required_count' => 1],
            ],
        ])
        ->assertForbidden();
});

test('duplicate ranks are rejected when syncing vessel manning', function () {
    [
        'user' => $user,
        'vessel' => $vessel,
        'captain' => $captain,
        'captainPosition' => $captainPosition,
    ] = makeVesselManningFixtures();

    $this->actingAs($user)
        ->from(route('organization.vessels.index'))
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [
                ['position_id' => $captainPosition->id, 'required_count' => 1],
                ['position_id' => $captainPosition->id, 'required_count' => 2],
            ],
        ])
        ->assertRedirect(route('organization.vessels.index'))
        ->assertSessionHasErrors('requirements.1.position_id');
});

test('inactive crew positions are rejected when syncing vessel manning', function () {
    [
        'user' => $user,
        'company' => $company,
        'vessel' => $vessel,
    ] = makeVesselManningFixtures();

    $inactivePosition = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Inactive Crew Position',
        'status' => 'inactive',
        'is_crew_position' => true,
    ]);

    $this->actingAs($user)
        ->from(route('organization.vessels.index'))
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [
                ['position_id' => $inactivePosition->id, 'required_count' => 1],
            ],
        ])
        ->assertRedirect(route('organization.vessels.index'))
        ->assertSessionHasErrors('requirements.0.position_id');
});

test('inactive vessels cannot be updated', function () {
    [
        'user' => $user,
        'inactiveVessel' => $inactiveVessel,
        'captain' => $captain,
        'captainPosition' => $captainPosition,
    ] = makeVesselManningFixtures();

    $this->actingAs($user)
        ->from(route('organization.vessels.index'))
        ->put(route('organization.vessel-manning.update', $inactiveVessel), [
            'requirements' => [
                ['position_id' => $captainPosition->id, 'required_count' => 1],
            ],
        ])
        ->assertRedirect(route('organization.vessels.index'))
        ->assertSessionHasErrors('vessel');
});

test('required count must be at least one', function () {
    [
        'user' => $user,
        'vessel' => $vessel,
        'captain' => $captain,
        'captainPosition' => $captainPosition,
    ] = makeVesselManningFixtures();

    $this->actingAs($user)
        ->from(route('organization.vessels.index'))
        ->put(route('organization.vessel-manning.update', $vessel), [
            'requirements' => [
                ['position_id' => $captainPosition->id, 'required_count' => 0],
            ],
        ])
        ->assertRedirect(route('organization.vessels.index'))
        ->assertSessionHasErrors('requirements.0.required_count');
});
