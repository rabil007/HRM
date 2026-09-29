<?php

use App\Models\Client;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\Project;
use App\Models\RecruitmentRequirement;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('guests cannot access projects page', function () {
    $this->get('/settings/master-data/projects')->assertRedirect(route('login'));
});

test('authorized users can view, create, update, and delete projects', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.view',
        'settings.master-data.projects.create',
        'settings.master-data.projects.update',
        'settings.master-data.projects.delete',
    ]);

    $client = Client::query()->create([
        'name' => 'ADNOC',
        'is_active' => true,
    ]);

    $this->get('/settings/master-data/projects')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/projects')
            ->has('clients')
            ->where('filters.client_id', null));

    $this->post('/settings/master-data/projects', [
        'client_id' => $client->id,
        'title' => 'North Field',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.projects.index'));

    $id = Project::query()->where('title', 'North Field')->value('id');
    expect($id)->not->toBeNull();
    expect(Project::query()->whereKey($id)->value('client_id'))->toBe($client->id);

    $this->put("/settings/master-data/projects/{$id}", [
        'client_id' => $client->id,
        'title' => 'South Field',
        'is_active' => false,
    ])->assertRedirect(route('settings.master-data.projects.index'));

    $this->assertDatabaseHas('projects', [
        'id' => $id,
        'client_id' => $client->id,
        'title' => 'South Field',
        'is_active' => 0,
    ]);

    $this->delete("/settings/master-data/projects/{$id}")
        ->assertRedirect(route('settings.master-data.projects.index'));

    $this->assertSoftDeleted('projects', ['id' => $id]);
});

test('authorized users can download csv template and import projects', function () {
    $this->seed(PermissionsSeeder::class);

    $user = User::factory()->create();
    $this->actingAs($user);

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.view',
        'settings.master-data.projects.create',
    ]);

    Client::query()->create([
        'name' => 'ADNOC',
        'is_active' => true,
    ]);

    $this->get('/settings/master-data/projects/import/template')
        ->assertOk()
        ->assertDownload();

    $csvContent = "client,project,is_active\nADNOC,Alpha Platform,no\nADNOC,Beta Field,yes\n";

    $this->post('/settings/master-data/projects/import', [
        'file' => UploadedFile::fake()->createWithContent('projects.csv', $csvContent),
    ])->assertRedirect('/settings/master-data/projects');

    expect(Project::query()->where('title', 'Alpha Platform')->value('is_active'))->toBe(false);
    expect(Project::query()->where('title', 'Beta Field')->value('is_active'))->toBe(true);
    expect(Project::query()->where('title', 'Alpha Platform')->value('client_id'))->not->toBeNull();
    expect(Project::query()->where('title', 'Beta Field')->value('client_id'))->not->toBeNull();
});

test('authorized users can create project with multiple clients', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.view',
        'settings.master-data.projects.create',
    ]);

    $clientA = Client::query()->create(['name' => 'Multi Client A', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Multi Client B', 'is_active' => true]);

    $this->post('/settings/master-data/projects', [
        'title' => 'Shared Campaign',
        'client_ids' => [$clientB->id, $clientA->id],
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.projects.index'));

    $project = Project::query()->where('title', 'Shared Campaign')->firstOrFail();

    expect($project->client_id)->toBe($clientA->id)
        ->and($project->clients()->pluck('clients.id')->sort()->values()->all())
        ->toBe([$clientA->id, $clientB->id]);
});

test('project index exposes clients and filters by relationship membership', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.view',
    ]);

    $clientA = Client::query()->create(['name' => 'Filter Client A', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Filter Client B', 'is_active' => true]);
    $clientC = Client::query()->create(['name' => 'Filter Client C', 'is_active' => true]);

    $shared = Project::query()->create(['title' => 'Shared Filter Project', 'client_id' => $clientA->id, 'is_active' => true]);
    $shared->clients()->sync([$clientA->id, $clientB->id]);
    $onlyC = Project::query()->create(['title' => 'Only C Project', 'client_id' => $clientC->id, 'is_active' => true]);
    $onlyC->clients()->sync([$clientC->id]);

    $this->get('/settings/master-data/projects?client_id='.$clientB->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/projects')
            ->has('projects', 1)
            ->where('projects.0.id', $shared->id)
            ->where('projects.0.client_ids', [$clientA->id, $clientB->id])
            ->where('projects.0.clients.0.name', 'Filter Client A')
            ->where('projects.0.clients.1.name', 'Filter Client B'));
});

test('legacy client_id payload normalizes to client_ids during project create and update', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.create',
        'settings.master-data.projects.update',
    ]);

    $clientA = Client::query()->create(['name' => 'Legacy Payload A', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Legacy Payload B', 'is_active' => true]);

    $this->post('/settings/master-data/projects', [
        'title' => 'Legacy Payload Project',
        'client_id' => $clientA->id,
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.projects.index'));

    $project = Project::query()->where('title', 'Legacy Payload Project')->firstOrFail();
    expect($project->clients()->pluck('clients.id')->all())->toBe([$clientA->id]);

    $this->put("/settings/master-data/projects/{$project->id}", [
        'title' => 'Legacy Payload Project',
        'client_id' => $clientB->id,
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.projects.index'));

    expect($project->fresh()->client_id)->toBe($clientB->id)
        ->and($project->fresh()->clients()->pluck('clients.id')->all())->toBe([$clientB->id]);
});

test('create-only user is rejected with 403 when quick creating existing project with new client', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.create',
    ]);

    $clientA = Client::query()->create(['name' => 'Quick Auth Client A', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Quick Auth Client B', 'is_active' => true]);
    $project = Project::query()->create([
        'title' => 'Quick Auth Project',
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);
    $project->clients()->sync([$clientA->id]);

    $this->postJson('/settings/master-data/projects', [
        'title' => 'Quick Auth Project',
        'client_id' => $clientB->id,
        'is_active' => true,
    ])->assertForbidden();

    expect($project->fresh()->clients()->pluck('clients.id')->all())
        ->toBe([$clientA->id])
        ->and($project->fresh()->client_id)->toBe($clientA->id);
});

test('create-only user can quick create existing project when requested client is already attached', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.create',
    ]);

    $clientA = Client::query()->create(['name' => 'Quick Existing Client A', 'is_active' => true]);
    $project = Project::query()->create([
        'title' => 'Quick Reuse Project',
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);
    $project->clients()->sync([$clientA->id]);

    $this->postJson('/settings/master-data/projects', [
        'title' => 'Quick Reuse Project',
        'client_id' => $clientA->id,
        'is_active' => true,
    ])->assertOk()
        ->assertJsonPath('id', $project->id);

    expect($project->fresh()->clients()->pluck('clients.id')->all())
        ->toBe([$clientA->id]);
});

test('user with update permission can quick create existing project to attach new client', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.create',
        'settings.master-data.projects.update',
    ]);

    $clientA = Client::query()->create(['name' => 'Quick Multi Client A', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Quick Multi Client B', 'is_active' => true]);
    $project = Project::query()->create([
        'title' => 'Quick Update Project',
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);
    $project->clients()->sync([$clientA->id]);

    $this->postJson('/settings/master-data/projects', [
        'title' => 'Quick Update Project',
        'client_id' => $clientB->id,
        'is_active' => true,
    ])->assertOk()
        ->assertJsonPath('id', $project->id);

    expect($project->fresh()->clients()->pluck('clients.id')->sort()->values()->all())
        ->toBe([$clientA->id, $clientB->id]);
});

test('project client removal is blocked while employees or recruitment requirements use the pair', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.update',
    ]);

    $clientA = Client::query()->create(['name' => 'Guard Client A', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Guard Client B', 'is_active' => true]);
    $project = Project::query()->create([
        'title' => 'Guarded Multi Project',
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);
    $project->clients()->sync([$clientA->id, $clientB->id]);

    Employee::factory()->forCompany($company)->create([
        'client_id' => $clientA->id,
        'project_id' => $project->id,
        'status' => 'active',
    ]);

    $this->put("/settings/master-data/projects/{$project->id}", [
        'title' => $project->title,
        'client_ids' => [$clientB->id],
        'is_active' => true,
    ])->assertSessionHasErrors('client_ids');

    Employee::query()->where('project_id', $project->id)->delete();

    RecruitmentRequirement::query()->create([
        'company_id' => $company->id,
        'requirement_number' => 'REQ-GUARD-1',
        'client_id' => $clientA->id,
        'project_id' => $project->id,
        'request_received_date' => now()->toDateString(),
        'required_by_date' => now()->addWeek()->toDateString(),
        'priority' => 'normal',
        'status' => 'open',
    ]);

    $this->put("/settings/master-data/projects/{$project->id}", [
        'title' => $project->title,
        'client_ids' => [$clientB->id],
        'is_active' => true,
    ])->assertSessionHasErrors('client_ids');

    expect($project->fresh()->clients()->pluck('clients.id')->sort()->values()->all())
        ->toBe([$clientA->id, $clientB->id]);
});

test('attaching client to project produces structured audit log entry', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.view',
        'settings.master-data.projects.update',
        'audit.view',
    ]);

    $clientA = Client::query()->create(['name' => 'Audit Client A', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Audit Client B', 'is_active' => true]);

    $project = Project::query()->create([
        'title' => 'Audit Attach Project',
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);
    $project->clients()->sync([$clientA->id]);

    $this->put("/settings/master-data/projects/{$project->id}", [
        'title' => $project->title,
        'client_ids' => [$clientA->id, $clientB->id],
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.projects.index'));

    $activity = Activity::query()
        ->where('subject_type', Project::class)
        ->where('subject_id', $project->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($user->id)
        ->and($activity->company_id)->toBe($company->id)
        ->and($activity->properties['added_client_ids'])->toBe([$clientB->id])
        ->and($activity->properties['removed_client_ids'])->toBe([])
        ->and($activity->properties['before_client_ids'])->toBe([$clientA->id])
        ->and($activity->properties['after_client_ids'])->toBe([$clientA->id, $clientB->id])
        ->and($activity->properties['added_client_names'])->toContain('Audit Client B');
});

test('removing client from project produces structured audit log entry', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.view',
        'settings.master-data.projects.update',
        'audit.view',
    ]);

    $clientA = Client::query()->create(['name' => 'Audit Client A', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Audit Client B', 'is_active' => true]);

    $project = Project::query()->create([
        'title' => 'Audit Remove Project',
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);
    $project->clients()->sync([$clientA->id, $clientB->id]);

    $this->put("/settings/master-data/projects/{$project->id}", [
        'title' => $project->title,
        'client_ids' => [$clientA->id],
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.projects.index'));

    $activity = Activity::query()
        ->where('subject_type', Project::class)
        ->where('subject_id', $project->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($user->id)
        ->and($activity->company_id)->toBe($company->id)
        ->and($activity->properties['added_client_ids'])->toBe([])
        ->and($activity->properties['removed_client_ids'])->toBe([$clientB->id])
        ->and($activity->properties['before_client_ids'])->toBe([$clientA->id, $clientB->id])
        ->and($activity->properties['after_client_ids'])->toBe([$clientA->id])
        ->and($activity->properties['removed_client_names'])->toContain('Audit Client B');
});

test('synchronizing the same clients without relationship changes does not create relationship audit entry', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.view',
        'settings.master-data.projects.update',
    ]);

    $clientA = Client::query()->create(['name' => 'Noop Client A', 'is_active' => true]);
    $project = Project::query()->create([
        'title' => 'Noop Project',
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);
    $project->clients()->sync([$clientA->id]);

    $beforeCount = Activity::query()->where('subject_type', Project::class)->count();

    $this->put("/settings/master-data/projects/{$project->id}", [
        'title' => $project->title,
        'client_ids' => [$clientA->id],
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.projects.index'));

    $afterCount = Activity::query()->where('subject_type', Project::class)->count();
    expect($afterCount)->toBe($beforeCount);
});

test('invalid inactive client later in client_ids array surfaces top-level client_ids validation error', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.create',
    ]);

    $clientA = Client::query()->create(['name' => 'Active A', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Active B', 'is_active' => true]);
    $clientC = Client::query()->create(['name' => 'Inactive C', 'is_active' => false]);

    $this->post('/settings/master-data/projects', [
        'title' => 'Invalid Index Project',
        'client_ids' => [$clientA->id, $clientB->id, $clientC->id],
        'is_active' => true,
    ])->assertSessionHasErrors([
        'client_ids' => 'The selected client is inactive.',
        'client_id' => 'The selected client is inactive.',
    ]);

    expect(Project::query()->where('title', 'Invalid Index Project')->exists())->toBeFalse();
});

test('nonexistent client id later in client_ids array surfaces top-level client_ids validation error', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.create',
    ]);

    $clientA = Client::query()->create(['name' => 'Active A', 'is_active' => true]);

    $this->post('/settings/master-data/projects', [
        'title' => 'Nonexistent Client Project',
        'client_ids' => [$clientA->id, 999999],
        'is_active' => true,
    ])->assertSessionHasErrors([
        'client_ids' => 'The selected client is invalid.',
        'client_id' => 'The selected client is invalid.',
    ]);

    expect(Project::query()->where('title', 'Nonexistent Client Project')->exists())->toBeFalse();
});

test('create-only user can import new projects and reuse existing matching projects without mutation', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.view',
        'settings.master-data.projects.create',
    ]);

    $clientA = Client::query()->create(['name' => 'Import Client Alpha', 'is_active' => true]);
    $existing = Project::query()->create([
        'title' => 'Alpha Existing Project',
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);
    $existing->clients()->sync([$clientA->id]);

    $csv = "client,project,is_active\nImport Client Alpha,Brand New Project,yes\nImport Client Alpha,Alpha Existing Project,yes\n";

    $this->post('/settings/master-data/projects/import', [
        'file' => UploadedFile::fake()->createWithContent('projects.csv', $csv),
    ])->assertRedirect('/settings/master-data/projects')
        ->assertSessionHas('success');

    $newProject = Project::query()->where('title', 'Brand New Project')->firstOrFail();
    expect($newProject->clients()->pluck('clients.id')->all())->toBe([$clientA->id])
        ->and($existing->fresh()->clients()->pluck('clients.id')->all())->toBe([$clientA->id]);
});

test('create-only user cannot attach new client or change is_active on existing project via import', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.view',
        'settings.master-data.projects.create',
    ]);

    $clientA = Client::query()->create(['name' => 'Client One', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Client Two', 'is_active' => true]);

    $existingActive = Project::query()->create([
        'title' => 'Existing Active Project',
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);
    $existingActive->clients()->sync([$clientA->id]);

    $existingStatus = Project::query()->create([
        'title' => 'Existing Status Project',
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);
    $existingStatus->clients()->sync([$clientA->id]);

    // Attempting to attach Client Two to Existing Active Project, and toggle Existing Status Project to no
    $csv = "client,project,is_active\nClient Two,Existing Active Project,yes\nClient One,Existing Status Project,no\n";

    $this->post('/settings/master-data/projects/import', [
        'file' => UploadedFile::fake()->createWithContent('projects.csv', $csv),
    ])->assertRedirect('/settings/master-data/projects')
        ->assertSessionHasErrors('file');

    expect($existingActive->fresh()->clients()->pluck('clients.id')->all())->toBe([$clientA->id])
        ->and($existingActive->fresh()->client_id)->toBe($clientA->id)
        ->and($existingStatus->fresh()->is_active)->toBeTrue();
});

test('user with create and update permissions can attach new client and update status on existing project via import', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.view',
        'settings.master-data.projects.create',
        'settings.master-data.projects.update',
    ]);

    $clientA = Client::query()->create(['name' => 'Client One Updatable', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Client Two Updatable', 'is_active' => true]);

    $existingActive = Project::query()->create([
        'title' => 'Updatable Project Attach',
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);
    $existingActive->clients()->sync([$clientA->id]);

    $existingStatus = Project::query()->create([
        'title' => 'Updatable Project Status',
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);
    $existingStatus->clients()->sync([$clientA->id]);

    $csv = "client,project,is_active\nClient Two Updatable,Updatable Project Attach,yes\nClient One Updatable,Updatable Project Status,no\n";

    $this->post('/settings/master-data/projects/import', [
        'file' => UploadedFile::fake()->createWithContent('projects.csv', $csv),
    ])->assertRedirect('/settings/master-data/projects')
        ->assertSessionHas('success');

    expect($existingActive->fresh()->clients()->pluck('clients.id')->sort()->values()->all())
        ->toBe([$clientA->id, $clientB->id])
        ->and($existingStatus->fresh()->is_active)->toBeFalse();
});
