<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Project;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('guests cannot view client operations show page', function () {
    $client = Client::query()->create(['name' => 'Guest Client', 'is_active' => true]);

    $this->get(route('settings.master-data.clients.show', $client))
        ->assertRedirect(route('login'));
});

test('unauthorized users cannot view client operations show page', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    $client = Client::query()->create(['name' => 'Forbidden Client', 'is_active' => true]);

    $this->get(route('settings.master-data.clients.show', $client))
        ->assertForbidden();
});

test('authorized users can view client operations show page with project and company vessel previews', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.projects.view',
        'crew_operations.vessels.view',
    ]);

    $client = Client::query()->create([
        'name' => 'Apex Marine Offshore',
        'is_active' => true,
    ]);

    $project1 = Project::query()->create([
        'title' => 'Campaign Alpha',
        'client_id' => $client->id,
        'is_active' => true,
    ]);

    $project2 = Project::query()->create([
        'title' => 'Maintenance Beta',
        'client_id' => $client->id,
        'is_active' => false,
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'AHTS '.Str::random(4),
        'is_active' => true,
    ]);

    $vessel1 = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'vessel_type_id' => $vesselType->id,
        'name' => 'Apex Pioneer',
        'imo_no' => '9123456',
        'is_active' => true,
    ]);

    $vessel2 = Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'vessel_type_id' => $vesselType->id,
        'name' => 'Apex Voyager',
        'imo_no' => '9654321',
        'is_active' => false,
    ]);

    $this->get(route('settings.master-data.clients.show', $client))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/client-show')
            ->where('client.id', $client->id)
            ->where('client.name', 'Apex Marine Offshore')
            ->where('client.is_active', true)
            ->where('operations.projects.total_count', 2)
            ->where('operations.projects.active_count', 1)
            ->where('operations.projects.preview.0.title', 'Campaign Alpha')
            ->where('operations.vessels.total_count', 2)
            ->where('operations.vessels.active_count', 1)
            ->where('operations.vessels.preview.0.name', 'Apex Pioneer')
            ->where('can.view_projects', true)
            ->where('can.view_vessels', true)
        );
});

test('tenancy isolation: client show and index pages only expose vessels for the active company', function () {
    ['user' => $userA, 'company' => $companyA] = makeCrewAssignmentFixtures();

    // Create Company B with another user
    $userB = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'CB'.fake()->unique()->numerify('##'),
        'name' => 'Company B Country',
        'dial_code' => '+002',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'CB'.fake()->unique()->numerify('##'),
        'name' => 'Company B Currency',
        'symbol' => 'B$',
        'is_active' => true,
    ]);
    $companyB = Company::query()->create([
        'name' => 'Company B Fleet',
        'slug' => 'company-b-'.Str::lower(Str::random(6)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($userA, $companyA, [
        'settings.master-data.clients.view',
        'crew_operations.vessels.view',
    ]);

    grantCompanyPermissions($userB, $companyB, [
        'settings.master-data.clients.view',
        'crew_operations.vessels.view',
    ]);

    $client = Client::query()->create([
        'name' => 'Shared Charterer '.Str::random(4),
        'is_active' => true,
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'Crew Boat '.Str::random(4),
        'is_active' => true,
    ]);

    // Vessel for Company A
    $vesselA = Vessel::query()->create([
        'company_id' => $companyA->id,
        'client_id' => $client->id,
        'vessel_type_id' => $vesselType->id,
        'name' => 'Vessel Company A',
        'is_active' => true,
    ]);

    // Vessel for Company B
    $vesselB = Vessel::query()->create([
        'company_id' => $companyB->id,
        'client_id' => $client->id,
        'vessel_type_id' => $vesselType->id,
        'name' => 'Vessel Company B',
        'is_active' => true,
    ]);

    // Under Company A:
    $this->actingAs($userA);

    // Index page vessel count for Company A
    $this->get(route('settings.master-data.clients.index', ['search' => $client->name]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/clients')
            ->where('clients.0.id', $client->id)
            ->where('clients.0.vessels_count', 1)
        );

    // Show page vessels preview for Company A
    $this->get(route('settings.master-data.clients.show', $client))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/client-show')
            ->where('operations.vessels.total_count', 1)
            ->where('operations.vessels.preview.0.id', $vesselA->id)
            ->where('operations.vessels.preview.0.name', 'Vessel Company A')
        );

    // Under Company B:
    $this->actingAs($userB);

    // Index page vessel count for Company B
    $this->get(route('settings.master-data.clients.index', ['search' => $client->name]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/clients')
            ->where('clients.0.id', $client->id)
            ->where('clients.0.vessels_count', 1)
        );

    // Show page vessels preview for Company B
    $this->get(route('settings.master-data.clients.show', $client))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/client-show')
            ->where('operations.vessels.total_count', 1)
            ->where('operations.vessels.preview.0.id', $vesselB->id)
            ->where('operations.vessels.preview.0.name', 'Vessel Company B')
        );
});

test('zero related records state is handled cleanly on show and index pages when authorized', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.projects.view',
        'crew_operations.vessels.view',
    ]);

    $client = Client::query()->create([
        'name' => 'Empty Client '.Str::random(4),
        'is_active' => true,
    ]);

    $this->get(route('settings.master-data.clients.index', ['search' => $client->name]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/clients')
            ->where('clients.0.id', $client->id)
            ->where('clients.0.projects_count', 0)
            ->where('clients.0.vessels_count', 0)
        );

    $this->get(route('settings.master-data.clients.show', $client))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/client-show')
            ->where('operations.projects.total_count', 0)
            ->where('operations.projects.active_count', 0)
            ->where('operations.projects.preview', [])
            ->where('operations.vessels.total_count', 0)
            ->where('operations.vessels.active_count', 0)
            ->where('operations.vessels.preview', [])
        );
});

test('project data and counts are hidden when user lacks projects view permission', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'crew_operations.vessels.view',
    ]);

    $client = Client::query()->create([
        'name' => 'Secret Project Client '.Str::random(4),
        'is_active' => true,
    ]);

    Project::query()->create([
        'title' => 'Secret Project Alpha',
        'client_id' => $client->id,
        'is_active' => true,
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'Tug '.Str::random(4),
        'is_active' => true,
    ]);

    Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'vessel_type_id' => $vesselType->id,
        'name' => 'Visible Vessel',
        'is_active' => true,
    ]);

    $this->get(route('settings.master-data.clients.index', ['search' => $client->name]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/clients')
            ->where('clients.0.id', $client->id)
            ->where('clients.0.projects_count', null)
            ->where('clients.0.vessels_count', 1)
            ->where('can.view_projects', false)
            ->where('can.view_vessels', true)
        );

    $this->get(route('settings.master-data.clients.show', $client))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/client-show')
            ->where('operations.projects', null)
            ->where('operations.vessels.total_count', 1)
            ->where('operations.vessels.preview.0.name', 'Visible Vessel')
            ->where('can.view_projects', false)
            ->where('can.view_vessels', true)
        );
});

test('vessel data and counts are hidden when user lacks vessels view permission', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.projects.view',
    ]);

    $client = Client::query()->create([
        'name' => 'Secret Vessel Client '.Str::random(4),
        'is_active' => true,
    ]);

    Project::query()->create([
        'title' => 'Visible Project',
        'client_id' => $client->id,
        'is_active' => true,
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'Barge '.Str::random(4),
        'is_active' => true,
    ]);

    Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'vessel_type_id' => $vesselType->id,
        'name' => 'Secret Vessel',
        'is_active' => true,
    ]);

    $this->get(route('settings.master-data.clients.index', ['search' => $client->name]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/clients')
            ->where('clients.0.id', $client->id)
            ->where('clients.0.projects_count', 1)
            ->where('clients.0.vessels_count', null)
            ->where('can.view_projects', true)
            ->where('can.view_vessels', false)
        );

    $this->get(route('settings.master-data.clients.show', $client))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/client-show')
            ->where('operations.projects.total_count', 1)
            ->where('operations.projects.preview.0.title', 'Visible Project')
            ->where('operations.vessels', null)
            ->where('can.view_projects', true)
            ->where('can.view_vessels', false)
        );
});

test('all operations data is hidden when user only has clients view permission', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
    ]);

    $client = Client::query()->create([
        'name' => 'Restricted Operations Client '.Str::random(4),
        'is_active' => true,
    ]);

    Project::query()->create([
        'title' => 'Unseen Project',
        'client_id' => $client->id,
        'is_active' => true,
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'Support Vessel '.Str::random(4),
        'is_active' => true,
    ]);

    Vessel::query()->create([
        'company_id' => $company->id,
        'client_id' => $client->id,
        'vessel_type_id' => $vesselType->id,
        'name' => 'Unseen Vessel',
        'is_active' => true,
    ]);

    $this->get(route('settings.master-data.clients.index', ['search' => $client->name]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/clients')
            ->where('clients.0.id', $client->id)
            ->where('clients.0.projects_count', null)
            ->where('clients.0.vessels_count', null)
            ->where('can.view_projects', false)
            ->where('can.view_vessels', false)
        );

    $this->get(route('settings.master-data.clients.show', $client))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/client-show')
            ->where('operations.projects', null)
            ->where('operations.vessels', null)
            ->where('can.view_projects', false)
            ->where('can.view_vessels', false)
        );
});

test('client update from show page redirects back to show', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.clients.update',
    ]);

    $client = Client::query()->create([
        'name' => 'Original Name '.Str::random(4),
        'is_active' => true,
    ]);

    $showUrl = route('settings.master-data.clients.show', $client);

    $this->from($showUrl)
        ->put("/settings/master-data/clients/{$client->id}", [
            'name' => 'Renamed Client',
            'is_active' => true,
        ])
        ->assertRedirect($showUrl);

    expect($client->fresh()->name)->toBe('Renamed Client');
});

test('client update with redirect_to_show explicitly redirects to show page', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.clients.update',
    ]);

    $client = Client::query()->create([
        'name' => 'Explicit Show Redirect '.Str::random(4),
        'is_active' => true,
    ]);

    $showUrl = route('settings.master-data.clients.show', $client);

    $this->put("/settings/master-data/clients/{$client->id}", [
        'name' => 'Renamed Explicit',
        'is_active' => true,
        'redirect_to_show' => true,
    ])
        ->assertRedirect($showUrl);

    expect($client->fresh()->name)->toBe('Renamed Explicit');
});

test('client update without redirect_to_show redirects back or to index', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.clients.update',
    ]);

    $client = Client::query()->create([
        'name' => 'Index Redirect Client '.Str::random(4),
        'is_active' => true,
    ]);

    $indexUrl = route('settings.master-data.clients.index');

    $this->from($indexUrl)
        ->put("/settings/master-data/clients/{$client->id}", [
            'name' => 'Updated from Index',
            'is_active' => true,
        ])
        ->assertRedirect($indexUrl);

    expect($client->fresh()->name)->toBe('Updated from Index');
});
