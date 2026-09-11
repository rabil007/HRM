<?php

use App\Models\Bank;
use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeSeaService;
use App\Models\Gender;
use App\Models\Rank;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselManning;
use App\Models\VesselType;
use Inertia\Testing\AssertableInertia as Assert;

function makeMasterDataUsageFixtures(array $permissions = []): array
{
    $user = User::factory()->create();
    $suffix = strtoupper(substr(str_replace('.', '', uniqid('', true)), -3));

    $country = Country::query()->create([
        'code' => 'U'.$suffix,
        'name' => 'Usage Land '.$suffix,
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'U'.$suffix,
        'name' => 'Usage Currency '.$suffix,
        'symbol' => 'U$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Usage Co '.$suffix,
        'slug' => 'usage-co-'.strtolower($suffix).'-'.uniqid(),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    if ($permissions !== []) {
        grantCompanyPermissions($user, $company, $permissions);
    }

    return compact('user', 'company', 'country', 'currency');
}

test('genders index exposes is_in_use and can_delete flags', function () {
    ['user' => $user, 'company' => $company] = makeMasterDataUsageFixtures([
        'settings.master-data.genders.view',
        'settings.master-data.genders.delete',
    ]);

    $this->actingAs($user);

    $unused = Gender::query()->create(['name' => 'Unused Gender', 'is_active' => true]);
    $used = Gender::query()->create(['name' => 'Used Gender', 'is_active' => true]);

    Employee::factory()->forCompany($company)->create([
        'gender_id' => $used->id,
        'status' => 'active',
    ]);

    $this->get('/settings/master-data/genders')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/genders')
            ->has('genders', 2)
            ->where('genders.0.id', $unused->id)
            ->where('genders.0.is_in_use', false)
            ->where('genders.0.can_delete', true)
            ->where('genders.1.id', $used->id)
            ->where('genders.1.is_in_use', true)
            ->where('genders.1.can_delete', false)
            ->where('genders.1.usage_label', 'employees')
        );
});

test('unused gender can be deleted and used gender cannot', function () {
    ['user' => $user, 'company' => $company] = makeMasterDataUsageFixtures([
        'settings.master-data.genders.view',
        'settings.master-data.genders.delete',
    ]);

    $this->actingAs($user);

    $unused = Gender::query()->create(['name' => 'Temp Gender', 'is_active' => true]);
    $used = Gender::query()->create(['name' => 'Male In Use', 'is_active' => true]);

    Employee::factory()->forCompany($company)->create([
        'gender_id' => $used->id,
        'status' => 'active',
    ]);

    $this->delete("/settings/master-data/genders/{$unused->id}")
        ->assertRedirect(route('settings.master-data.genders.index'));

    $this->assertSoftDeleted('genders', ['id' => $unused->id]);

    $this->from(route('settings.master-data.genders.index'))
        ->delete("/settings/master-data/genders/{$used->id}")
        ->assertRedirect(route('settings.master-data.genders.index'))
        ->assertSessionHasErrors('record');

    expect(Gender::query()->whereKey($used->id)->exists())->toBeTrue();
    $this->assertStringContainsString('Male In Use', session('errors')->get('record')[0]);
});

test('unauthorized user cannot delete unused gender', function () {
    ['user' => $user] = makeMasterDataUsageFixtures([
        'settings.master-data.genders.view',
    ]);

    $this->actingAs($user);

    $gender = Gender::query()->create(['name' => 'No Delete', 'is_active' => true]);

    $this->delete("/settings/master-data/genders/{$gender->id}")
        ->assertForbidden();

    expect(Gender::query()->whereKey($gender->id)->exists())->toBeTrue();
});

test('country referenced by company cannot be deleted', function () {
    ['user' => $user, 'country' => $country] = makeMasterDataUsageFixtures([
        'settings.master-data.countries.view',
        'settings.master-data.countries.delete',
    ]);

    $this->actingAs($user);

    $this->from(route('settings.master-data.countries.index'))
        ->delete("/settings/master-data/countries/{$country->id}")
        ->assertRedirect(route('settings.master-data.countries.index'))
        ->assertSessionHasErrors('record');

    expect(Country::query()->whereKey($country->id)->exists())->toBeTrue()
        ->and(Country::query()->whereKey($country->id)->value('is_active'))->toBeTrue();
});

test('soft-deleted employee gender reference does not block deletion', function () {
    ['user' => $user, 'company' => $company] = makeMasterDataUsageFixtures([
        'settings.master-data.genders.view',
        'settings.master-data.genders.delete',
    ]);

    $this->actingAs($user);

    $gender = Gender::query()->create(['name' => 'Historical Only', 'is_active' => true]);
    $employee = Employee::factory()->forCompany($company)->create([
        'gender_id' => $gender->id,
        'status' => 'active',
    ]);
    $employee->delete();

    $this->delete("/settings/master-data/genders/{$gender->id}")
        ->assertRedirect(route('settings.master-data.genders.index'));

    $this->assertSoftDeleted('genders', ['id' => $gender->id]);
});

test('bank used by employee bank account or payroll cannot be deleted', function () {
    ['user' => $user, 'company' => $company] = makeMasterDataUsageFixtures([
        'settings.master-data.banks.view',
        'settings.master-data.banks.delete',
    ]);

    $this->actingAs($user);

    $bank = Bank::query()->create([
        'name' => 'Linked Bank',
        'uae_routing_code_agent_id' => '123456',
        'is_active' => true,
    ]);

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    EmployeeBankAccount::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'bank_id' => $bank->id,
        'iban' => 'AE070331234567890123456',
        'account_name' => 'Test',
        'is_primary' => true,
    ]);

    $this->from(route('settings.master-data.banks.index'))
        ->delete(route('settings.master-data.banks.destroy', $bank))
        ->assertRedirect(route('settings.master-data.banks.index'))
        ->assertSessionHasErrors('record');

    expect(Bank::query()->whereKey($bank->id)->exists())->toBeTrue();
});

test('client used by employee cannot be deleted', function () {
    ['user' => $user, 'company' => $company] = makeMasterDataUsageFixtures([
        'settings.master-data.clients.view',
        'settings.master-data.clients.delete',
    ]);

    $this->actingAs($user);

    $client = Client::query()->create(['name' => 'Charter Client', 'is_active' => true]);

    Employee::factory()->forCompany($company)->create([
        'client_id' => $client->id,
        'status' => 'active',
    ]);

    $this->from(route('settings.master-data.clients.index'))
        ->delete("/settings/master-data/clients/{$client->id}")
        ->assertRedirect(route('settings.master-data.clients.index'))
        ->assertSessionHasErrors('record');

    expect(Client::query()->whereKey($client->id)->exists())->toBeTrue();
});

test('rank used by vessel manning blocks deletion and appears in use on index', function () {
    ['user' => $user, 'company' => $company] = makeMasterDataUsageFixtures([
        'settings.master-data.ranks.view',
        'settings.master-data.ranks.delete',
    ]);

    $this->actingAs($user);

    $rank = Rank::query()->create(['name' => 'Captain', 'is_active' => true]);
    $vesselType = VesselType::query()->create(['name' => 'AHTS', 'is_active' => true]);
    $vessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'MV Ranked',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    VesselManning::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'required_count' => 1,
    ]);

    $this->get('/settings/master-data/ranks')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/ranks')
            ->where('ranks.0.id', $rank->id)
            ->where('ranks.0.is_in_use', true)
            ->where('ranks.0.can_delete', false)
        );

    $this->from(route('settings.master-data.ranks.index'))
        ->delete("/settings/master-data/ranks/{$rank->id}")
        ->assertRedirect(route('settings.master-data.ranks.index'))
        ->assertSessionHasErrors('record');

    expect(Rank::query()->whereKey($rank->id)->exists())->toBeTrue();
});

test('cross-company vessel usage does not block another company vessel delete', function () {
    ['user' => $user, 'company' => $company] = makeMasterDataUsageFixtures([
        'crew_operations.vessels.view',
        'crew_operations.vessels.delete',
    ]);

    $other = makeMasterDataUsageFixtures();
    $otherCompany = $other['company'];

    $vesselType = VesselType::query()->create(['name' => 'OSV', 'is_active' => true]);

    $ownVessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'Own Free Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $foreignVessel = Vessel::query()->create([
        'company_id' => $otherCompany->id,
        'name' => 'Foreign Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ]);

    $employee = Employee::factory()->forCompany($otherCompany)->create(['status' => 'active']);
    EmployeeSeaService::factory()->forEmployee($employee)->create([
        'vessel_type_id' => $vesselType->id,
        'vessel_id' => $foreignVessel->id,
    ]);

    $this->actingAs($user)
        ->delete(route('organization.vessels.destroy', $ownVessel))
        ->assertRedirect(route('organization.vessels.index'));

    $this->assertSoftDeleted('vessels', ['id' => $ownVessel->id]);
});
