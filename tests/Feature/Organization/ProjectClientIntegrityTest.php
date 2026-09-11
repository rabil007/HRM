<?php

use App\Models\Client;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Vessel;
use App\Models\VesselType;
use Illuminate\Http\UploadedFile;

test('legacy null project can be assigned a client', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.view',
        'settings.master-data.projects.update',
    ]);

    $project = Project::query()->create([
        'title' => 'Legacy Assignable '.uniqid(),
        'client_id' => null,
        'is_active' => true,
    ]);
    $client = Client::query()->create(['name' => 'First Client', 'is_active' => true]);

    $this->put("/settings/master-data/projects/{$project->id}", [
        'title' => $project->title,
        'client_id' => $client->id,
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.projects.index'));

    expect((int) $project->fresh()->client_id)->toBe((int) $client->id);
});

test('unused project can change client without employees', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.update',
    ]);

    $clientA = Client::query()->create(['name' => 'Client A Unused', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Client B Unused', 'is_active' => true]);
    $project = Project::query()->create([
        'title' => 'Unused Project '.uniqid(),
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);

    $this->put("/settings/master-data/projects/{$project->id}", [
        'title' => $project->title,
        'client_id' => $clientB->id,
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.projects.index'));

    expect((int) $project->fresh()->client_id)->toBe((int) $clientB->id);
});

test('project referenced by employee with conflicting client cannot be re-parented', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.update',
    ]);

    $clientA = Client::query()->create(['name' => 'Client A Locked', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Client B Locked', 'is_active' => true]);
    $project = Project::query()->create([
        'title' => 'Locked Project '.uniqid(),
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);

    Employee::factory()->forCompany($company)->create([
        'client_id' => $clientA->id,
        'project_id' => $project->id,
        'status' => 'active',
    ]);

    $this->put("/settings/master-data/projects/{$project->id}", [
        'title' => $project->title,
        'client_id' => $clientB->id,
        'is_active' => true,
    ])->assertSessionHasErrors('client_id');

    expect((int) $project->fresh()->client_id)->toBe((int) $clientA->id);
});

test('mapped project and vessel cannot return to unassigned', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.update',
        'crew_operations.vessels.update',
    ]);

    $client = Client::query()->create(['name' => 'Mapped Client', 'is_active' => true]);
    $project = Project::query()->create([
        'title' => 'Mapped Project '.uniqid(),
        'client_id' => $client->id,
        'is_active' => true,
    ]);

    $this->put("/settings/master-data/projects/{$project->id}", [
        'title' => $project->title,
        'client_id' => '',
        'is_active' => true,
    ])->assertSessionHasErrors('client_id');

    expect((int) $project->fresh()->client_id)->toBe((int) $client->id);

    $vessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'Mapped Vessel '.uniqid(),
        'client_id' => $client->id,
        'vessel_type_id' => VesselType::query()->create(['name' => 'VT '.uniqid(), 'is_active' => true])->id,
        'is_active' => true,
    ]);

    $this->put("/organization/vessels/{$vessel->id}", [
        'name' => $vessel->name,
        'client_id' => '',
        'vessel_type_id' => $vessel->vessel_type_id,
        'is_active' => true,
    ])->assertSessionHasErrors('client_id');

    expect((int) $vessel->fresh()->client_id)->toBe((int) $client->id);
});

test('legacy null project and vessel can remain null while editing other fields', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.update',
        'crew_operations.vessels.update',
    ]);

    $project = Project::query()->create([
        'title' => 'Stay Null Project '.uniqid(),
        'client_id' => null,
        'is_active' => true,
    ]);

    $this->put("/settings/master-data/projects/{$project->id}", [
        'title' => $project->title.' Updated',
        'client_id' => '',
        'is_active' => false,
    ])->assertRedirect(route('settings.master-data.projects.index'));

    expect($project->fresh()->client_id)->toBeNull()
        ->and($project->fresh()->is_active)->toBeFalse();

    $vessel = Vessel::query()->create([
        'company_id' => $company->id,
        'name' => 'Stay Null Vessel '.uniqid(),
        'client_id' => null,
        'vessel_type_id' => VesselType::query()->create(['name' => 'VT '.uniqid(), 'is_active' => true])->id,
        'is_active' => true,
    ]);

    $this->put("/organization/vessels/{$vessel->id}", [
        'name' => $vessel->name.' Updated',
        'client_id' => '',
        'vessel_type_id' => $vessel->vessel_type_id,
        'is_active' => false,
    ])->assertRedirect();

    expect($vessel->fresh()->client_id)->toBeNull()
        ->and($vessel->fresh()->is_active)->toBeFalse();
});

test('project import respects re-parenting guard and can map legacy null projects', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.view',
        'settings.master-data.projects.create',
    ]);

    $clientA = Client::query()->create(['name' => 'Import Client A', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Import Client B', 'is_active' => true]);

    $legacy = Project::query()->create([
        'title' => 'Import Legacy',
        'client_id' => null,
        'is_active' => true,
    ]);

    $locked = Project::query()->create([
        'title' => 'Import Locked',
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);

    Employee::factory()->forCompany($company)->create([
        'client_id' => $clientA->id,
        'project_id' => $locked->id,
        'status' => 'active',
    ]);

    $csv = "client,project,is_active\nImport Client A,Import Legacy,yes\nImport Client B,Import Locked,yes\n";

    $this->post('/settings/master-data/projects/import', [
        'file' => UploadedFile::fake()->createWithContent('projects.csv', $csv),
    ])->assertRedirect('/settings/master-data/projects');

    expect((int) $legacy->fresh()->client_id)->toBe((int) $clientA->id)
        ->and((int) $locked->fresh()->client_id)->toBe((int) $clientA->id);
});

test('employee update can assign legacy null project without client', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.update',
    ]);

    $project = Project::query()->create([
        'title' => 'Legacy Emp Project '.uniqid(),
        'client_id' => null,
        'is_active' => true,
    ]);

    $this->put("/organization/employees/{$employee->id}", [
        'name' => $employee->name,
        'project_id' => $project->id,
    ])->assertRedirect();

    expect((int) $employee->fresh()->project_id)->toBe((int) $project->id)
        ->and($employee->fresh()->client_id)->toBeNull();
});
