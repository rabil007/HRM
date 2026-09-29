<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Project;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function makeClientHubFixtures(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'CHB'.fake()->unique()->numerify('##'),
        'name' => 'Client Hub Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'CHB'.fake()->unique()->numerify('##'),
        'name' => 'Client Hub Currency',
        'symbol' => 'C$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Client Hub Co',
        'slug' => 'client-hub-co-'.Str::lower(Str::random(6)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $otherCompany = Company::query()->create([
        'name' => 'Other Hub Co',
        'slug' => 'other-hub-co-'.Str::lower(Str::random(6)),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $vesselType = VesselType::query()->create([
        'name' => 'Tug '.Str::random(4),
        'is_active' => true,
    ]);

    $client = Client::query()->create([
        'name' => 'Client Hub Primary',
        'is_active' => true,
    ]);

    return compact('user', 'company', 'otherCompany', 'vesselType', 'client');
}

test('authorized user can create project from client operations hub', function () {
    ['user' => $user, 'company' => $company, 'client' => $client] = makeClientHubFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.projects.create',
    ]);

    $showUrl = route('settings.master-data.clients.show', $client);

    $this->from($showUrl)
        ->post(route('settings.master-data.clients.projects.store', $client), [
            'title' => 'Project Created From Hub',
            'is_active' => true,
        ])
        ->assertRedirect($showUrl);

    $project = Project::query()->where('title', 'Project Created From Hub')->first();
    expect($project)->not->toBeNull()
        ->and(Project::query()->where('title', 'Project Created From Hub')->count())->toBe(1)
        ->and($project->clients()->where('clients.id', $client->id)->exists())->toBeTrue();
});

test('cannot create project with duplicate title, guides user to attach existing', function () {
    ['user' => $user, 'company' => $company, 'client' => $client] = makeClientHubFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.projects.create',
    ]);

    $existing = Project::query()->create([
        'title' => 'Existing Hub Project',
        'is_active' => true,
    ]);

    $this->post(route('settings.master-data.clients.projects.store', $client), [
        'title' => 'Existing Hub Project',
        'is_active' => true,
    ])
        ->assertSessionHasErrors(['title' => 'A project with this title already exists. Use "Attach Existing" to assign it to this client.']);

    expect(Project::query()->where('title', 'Existing Hub Project')->count())->toBe(1);
});

test('authorized user can attach existing active project to client', function () {
    ['user' => $user, 'company' => $company, 'client' => $clientB] = makeClientHubFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.projects.update',
    ]);

    $clientA = Client::query()->create([
        'name' => 'Original Client',
        'is_active' => true,
    ]);

    $project = Project::query()->create([
        'title' => 'Cross Client Project',
        'is_active' => true,
    ]);
    $project->clients()->sync([$clientA->id]);

    $showUrl = route('settings.master-data.clients.show', $clientB);

    $this->from($showUrl)
        ->post(route('settings.master-data.clients.projects.attach', $clientB), [
            'project_id' => $project->id,
        ])
        ->assertRedirect($showUrl);

    expect(Project::query()->where('title', 'Cross Client Project')->count())->toBe(1);

    $clientIds = $project->fresh()->clients->pluck('id')->all();
    expect($clientIds)->toContain($clientA->id)
        ->and($clientIds)->toContain($clientB->id);
});

test('attaching already-attached project is idempotent and returns friendly message', function () {
    ['user' => $user, 'company' => $company, 'client' => $client] = makeClientHubFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.projects.update',
    ]);

    $project = Project::query()->create([
        'title' => 'Already Attached Project',
        'is_active' => true,
    ]);
    $project->clients()->sync([$client->id]);

    $showUrl = route('settings.master-data.clients.show', $client);

    $this->from($showUrl)
        ->post(route('settings.master-data.clients.projects.attach', $client), [
            'project_id' => $project->id,
        ])
        ->assertRedirect($showUrl)
        ->assertSessionHas('status', "Project '{$project->title}' is already assigned to this client.");

    expect($project->clients()->count())->toBe(1);
});

test('user with projects.create but lacking projects.update can create but cannot attach', function () {
    ['user' => $user, 'company' => $company, 'client' => $client] = makeClientHubFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.projects.create',
    ]);

    $project = Project::query()->create([
        'title' => 'Attach Forbidden Project',
        'is_active' => true,
    ]);

    // Create succeeds
    $this->post(route('settings.master-data.clients.projects.store', $client), [
        'title' => 'Brand New Created Project',
        'is_active' => true,
    ])->assertRedirect();

    // Attach fails 403
    $this->post(route('settings.master-data.clients.projects.attach', $client), [
        'project_id' => $project->id,
    ])->assertForbidden();
});

test('user with projects.update but lacking projects.create can attach but cannot create', function () {
    ['user' => $user, 'company' => $company, 'client' => $client] = makeClientHubFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.projects.update',
    ]);

    $project = Project::query()->create([
        'title' => 'Attach Allowed Project',
        'is_active' => true,
    ]);

    // Create fails 403
    $this->post(route('settings.master-data.clients.projects.store', $client), [
        'title' => 'Create Forbidden Project',
        'is_active' => true,
    ])->assertForbidden();

    // Attach succeeds
    $this->post(route('settings.master-data.clients.projects.attach', $client), [
        'project_id' => $project->id,
    ])->assertRedirect();
});

test('inactive client rejects project creation and project attachment', function () {
    ['user' => $user, 'company' => $company] = makeClientHubFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.projects.create',
        'settings.master-data.projects.update',
    ]);

    $inactiveClient = Client::query()->create([
        'name' => 'Inactive Client Hub',
        'is_active' => false,
    ]);

    $project = Project::query()->create([
        'title' => 'Attachable Project',
        'is_active' => true,
    ]);

    $this->post(route('settings.master-data.clients.projects.store', $inactiveClient), [
        'title' => 'Project Under Inactive',
        'is_active' => true,
    ])->assertSessionHasErrors(['title' => 'Activate this client before adding new projects or vessels.']);

    $this->post(route('settings.master-data.clients.projects.attach', $inactiveClient), [
        'project_id' => $project->id,
    ])->assertSessionHasErrors(['project_id' => 'Activate this client before adding new projects or vessels.']);
});

test('cannot attach inactive project to client', function () {
    ['user' => $user, 'company' => $company, 'client' => $client] = makeClientHubFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.projects.update',
    ]);

    $inactiveProject = Project::query()->create([
        'title' => 'Inactive Project Item',
        'is_active' => false,
    ]);

    $this->post(route('settings.master-data.clients.projects.attach', $client), [
        'project_id' => $inactiveProject->id,
    ])->assertSessionHasErrors(['project_id' => 'Cannot attach an inactive project. Please activate the project first.']);

    expect($client->projects()->count())->toBe(0);
});

test('authorized user can create vessel from client operations hub with certificate', function () {
    Storage::fake('public');

    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType, 'client' => $client] = makeClientHubFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'crew_operations.vessels.create',
    ]);

    $certificate = UploadedFile::fake()->create('hub-cert.pdf', 150, 'application/pdf');

    $showUrl = route('settings.master-data.clients.show', $client);

    $this->from($showUrl)
        ->post(route('settings.master-data.clients.vessels.store', $client), [
            'name' => 'Hub Pioneer Vessel',
            'vessel_type_id' => $vesselType->id,
            'grt' => 3200,
            'bhp' => 8500,
            'official_no' => 'OFF-999',
            'call_sign' => 'CALL-99',
            'imo_no' => '9988776',
            'is_active' => true,
            'certificate' => $certificate,
        ])
        ->assertRedirect($showUrl);

    $vessel = Vessel::query()->where('name', 'Hub Pioneer Vessel')->first();
    expect($vessel)->not->toBeNull()
        ->and((int) $vessel->company_id)->toBe((int) $company->id)
        ->and((int) $vessel->client_id)->toBe((int) $client->id)
        ->and($vessel->certificate_original_filename)->toBe('hub-cert.pdf')
        ->and($vessel->certificate_path)->not->toBeNull();

    Storage::disk('public')->assertExists($vessel->certificate_path);
});

test('vessel creation rejects client tampering when client_id does not match route', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType, 'client' => $clientA] = makeClientHubFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'crew_operations.vessels.create',
    ]);

    $clientB = Client::query()->create([
        'name' => 'Tampered Client B',
        'is_active' => true,
    ]);

    $this->post(route('settings.master-data.clients.vessels.store', $clientA), [
        'client_id' => $clientB->id, // Tampered
        'name' => 'Tampered Vessel',
        'vessel_type_id' => $vesselType->id,
        'grt' => 2000,
        'bhp' => 5000,
        'is_active' => true,
    ])->assertSessionHasErrors(['client_id']);

    expect(Vessel::query()->where('name', 'Tampered Vessel')->exists())->toBeFalse();
});

test('vessel creation ignores forged company_id and strictly uses current company', function () {
    ['user' => $user, 'company' => $company, 'otherCompany' => $otherCompany, 'vesselType' => $vesselType, 'client' => $client] = makeClientHubFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'crew_operations.vessels.create',
    ]);

    $this->post(route('settings.master-data.clients.vessels.store', $client), [
        'company_id' => $otherCompany->id, // Forged
        'name' => 'Tenancy Enforced Vessel',
        'vessel_type_id' => $vesselType->id,
        'grt' => 2000,
        'bhp' => 5000,
        'is_active' => true,
    ])->assertRedirect();

    $vessel = Vessel::query()->where('name', 'Tenancy Enforced Vessel')->first();
    expect($vessel)->not->toBeNull()
        ->and((int) $vessel->company_id)->toBe((int) $company->id)
        ->and((int) $vessel->company_id)->not->toBe((int) $otherCompany->id);
});

test('vessel creation is forbidden without crew_operations.vessels.create permission', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType, 'client' => $client] = makeClientHubFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
    ]);

    $this->post(route('settings.master-data.clients.vessels.store', $client), [
        'name' => 'Forbidden Vessel',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ])->assertForbidden();
});

test('inactive client rejects vessel creation', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType] = makeClientHubFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'crew_operations.vessels.create',
    ]);

    $inactiveClient = Client::query()->create([
        'name' => 'Inactive Client Vessel',
        'is_active' => false,
    ]);

    $this->post(route('settings.master-data.clients.vessels.store', $inactiveClient), [
        'name' => 'Vessel Under Inactive',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ])->assertSessionHasErrors(['name' => 'Activate this client before adding new projects or vessels.']);
});

test('client show page provides attachable_projects with assignment state and vessel_types when authorized', function () {
    ['user' => $user, 'company' => $company, 'vesselType' => $vesselType, 'client' => $client] = makeClientHubFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.projects.view',
        'settings.master-data.projects.update',
        'crew_operations.vessels.create',
    ]);

    $projectAttached = Project::query()->create([
        'title' => 'Attached Hub Proj',
        'is_active' => true,
    ]);
    $projectAttached->clients()->sync([$client->id]);

    $projectUnattached = Project::query()->create([
        'title' => 'Unattached Hub Proj',
        'is_active' => true,
    ]);

    $this->get(route('settings.master-data.clients.show', $client))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/client-show')
            ->where('can.attach_project', true)
            ->where('can.create_vessel', true)
            ->has('attachable_projects', 2)
            ->where('attachable_projects.0.id', $projectAttached->id)
            ->where('attachable_projects.0.is_already_assigned', true)
            ->where('attachable_projects.1.id', $projectUnattached->id)
            ->where('attachable_projects.1.is_already_assigned', false)
            ->has('vessel_types', 1)
            ->where('vessel_types.0.id', $vesselType->id)
        );
});

test('attach project permission is false and attachable_projects is empty when user has update but lacks view', function () {
    ['user' => $user, 'company' => $company, 'client' => $client] = makeClientHubFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.projects.update',
    ]);

    Project::query()->create([
        'title' => 'Hidden From Attach Proj',
        'is_active' => true,
    ]);

    $this->get(route('settings.master-data.clients.show', $client))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/client-show')
            ->where('can.attach_project', false)
            ->where('attachable_projects', [])
        );
});
