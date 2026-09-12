<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Exceptions\CrewMovementException;
use App\Models\Client;
use App\Models\CrewAssignment;
use App\Models\CrewPlanningAssignment;
use App\Models\Employee;
use App\Models\EmployeeProfileTemplate;
use App\Models\Project;
use App\Support\CrewMovements\CrewMovementService;
use App\Support\CrewPlanning\CreateCrewAssignmentFromPlanning;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateFieldRegistry;
use App\Support\Vessels\ResolvesCompanyVessels;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

test('project create requires active client and rejects inactive client', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.view',
        'settings.master-data.projects.create',
        'settings.master-data.projects.update',
    ]);

    $active = Client::query()->create(['name' => 'Active Client', 'is_active' => true]);
    $inactive = Client::query()->create(['name' => 'Inactive Client', 'is_active' => false]);

    $this->post('/settings/master-data/projects', [
        'title' => 'Needs Client',
        'is_active' => true,
    ])->assertSessionHasErrors('client_id');

    $this->post('/settings/master-data/projects', [
        'client_id' => $inactive->id,
        'title' => 'Inactive Project',
        'is_active' => true,
    ])->assertSessionHasErrors('client_id');

    $this->post('/settings/master-data/projects', [
        'client_id' => $active->id,
        'title' => 'Valid Project',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.projects.index'));

    $this->assertDatabaseHas('projects', [
        'title' => 'Valid Project',
        'client_id' => $active->id,
    ]);
});

test('legacy project with null client remains readable and mappable', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.view',
        'settings.master-data.projects.update',
    ]);

    $legacy = Project::query()->create([
        'title' => 'Legacy Project '.uniqid(),
        'client_id' => null,
        'is_active' => true,
    ]);

    $client = Client::query()->create(['name' => 'Mapped Client', 'is_active' => true]);

    $this->get('/settings/master-data/projects?search='.urlencode($legacy->title))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/projects')
            ->where('projects.0.id', $legacy->id)
            ->where('projects.0.client_id', null)
            ->where('projects.0.client_name', null));

    $this->put("/settings/master-data/projects/{$legacy->id}", [
        'client_id' => $client->id,
        'title' => $legacy->title,
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.projects.index'));

    expect((int) $legacy->fresh()->client_id)->toBe((int) $client->id);
});

test('client deletion is blocked when used by project or vessel', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.view',
        'settings.master-data.clients.delete',
    ]);

    $client = Client::query()->create(['name' => 'In Use Client '.uniqid(), 'is_active' => true]);

    Project::query()->create([
        'title' => 'Client Project '.uniqid(),
        'client_id' => $client->id,
        'is_active' => true,
    ]);

    $this->from(route('settings.master-data.clients.index'))
        ->delete("/settings/master-data/clients/{$client->id}")
        ->assertRedirect(route('settings.master-data.clients.index'))
        ->assertSessionHasErrors('record');

    expect(Client::query()->whereKey($client->id)->exists())->toBeTrue();

    Project::query()->where('client_id', $client->id)->forceDelete();

    $vessel = makeCrewMovementVessel('Client Vessel', $company);
    $vessel->update(['client_id' => $client->id]);

    $this->from(route('settings.master-data.clients.index'))
        ->delete("/settings/master-data/clients/{$client->id}")
        ->assertRedirect(route('settings.master-data.clients.index'))
        ->assertSessionHasErrors('record');
});

test('cross-company vessel usage metadata for client does not leak', function () {
    ['user' => $user, 'company' => $companyA] = makeCrewAssignmentFixtures();
    ['company' => $companyB] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $companyA, [
        'settings.master-data.clients.view',
        'settings.master-data.clients.delete',
    ]);

    $client = Client::query()->create(['name' => 'Shared Client '.uniqid(), 'is_active' => true]);
    $vesselB = makeCrewMovementVessel('Other Co Vessel', $companyB);
    $vesselB->update(['client_id' => $client->id]);

    $this->get('/settings/master-data/clients?search='.urlencode($client->name))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/clients')
            ->where('clients.0.id', $client->id)
            ->where('clients.0.is_in_use', true)
            ->where('clients.0.can_delete', false)
            ->where('clients.0.usage_count', null)
            ->where('clients.0.usage_label', null));
});

test('vessel stays company scoped and rejects cross-company access', function () {
    ['user' => $user, 'company' => $companyA] = makeCrewAssignmentFixtures();
    ['company' => $companyB] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $companyA, [
        'crew_operations.vessels.view',
        'crew_operations.vessels.update',
    ]);

    $client = Client::query()->create(['name' => 'Scoped Client', 'is_active' => true]);
    $vesselB = makeCrewMovementVessel('Foreign Vessel', $companyB);
    $vesselB->update(['client_id' => $client->id]);

    $this->get("/organization/vessels/{$vesselB->id}")->assertNotFound();

    $this->put("/organization/vessels/{$vesselB->id}", [
        'client_id' => $client->id,
        'name' => 'Hacked',
        'vessel_type_id' => $vesselB->vessel_type_id,
        'is_active' => true,
    ])->assertNotFound();
});

test('employee rejects mismatched client and project', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.update',
    ]);

    $adnoc = Client::query()->create(['name' => 'ADNOC Emp', 'is_active' => true]);
    $nmdc = Client::query()->create(['name' => 'NMDC Emp', 'is_active' => true]);
    $adnocProject = Project::query()->create([
        'title' => 'ADNOC Only Project '.uniqid(),
        'client_id' => $adnoc->id,
        'is_active' => true,
    ]);

    $this->put("/organization/employees/{$employee->id}", [
        'name' => $employee->name,
        'client_id' => $nmdc->id,
        'project_id' => $adnocProject->id,
    ])->assertSessionHasErrors('project_id');

    $this->put("/organization/employees/{$employee->id}", [
        'name' => $employee->name,
        'client_id' => $adnoc->id,
        'project_id' => $adnocProject->id,
    ])->assertRedirect();

    expect((int) $employee->fresh()->client_id)->toBe((int) $adnoc->id)
        ->and((int) $employee->fresh()->project_id)->toBe((int) $adnocProject->id);
});

test('crew assignment rejects mismatched client and vessel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);

    $adnoc = Client::query()->create(['name' => 'ADNOC Crew', 'is_active' => true]);
    $nmdc = Client::query()->create(['name' => 'NMDC Crew', 'is_active' => true]);
    $vessel = makeCrewMovementVessel('ADNOC Vessel', $company);
    $vessel->update(['client_id' => $adnoc->id]);

    $this->post('/organization/crew', [
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'client_id' => $nmdc->id,
        'vessel_id' => $vessel->id,
    ])->assertSessionHasErrors('client_id');

    $this->post('/organization/crew', [
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ])->assertRedirect();

    $assignment = CrewAssignment::query()->where('employee_id', $employee->id)->first();
    expect($assignment)->not->toBeNull()
        ->and((int) $assignment->client_id)->toBe((int) $adnoc->id)
        ->and((int) $assignment->vessel_id)->toBe((int) $vessel->id);
});

test('transfer vessel resolves destination client from destination vessel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $service = app(CrewMovementService::class);

    $adnoc = Client::query()->create(['name' => 'ADNOC Transfer', 'is_active' => true]);
    $nmdc = Client::query()->create(['name' => 'NMDC Transfer', 'is_active' => true]);

    $vesselA = makeCrewMovementVessel('Vessel A', $company);
    $vesselA->update(['client_id' => $adnoc->id]);
    $vesselB = makeCrewMovementVessel('Vessel B', $company);
    $vesselB->update(['client_id' => $nmdc->id]);

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vesselA, [
        'client_id' => $adnoc->id,
    ]);

    $destination = $service->perform($company->id, $assignment->id, CrewMovementAction::TransferVessel, [
        'occurred_at' => '2026-02-01 08:00:00',
        'vessel_id' => $vesselB->id,
        'rank_id' => $rank->id,
    ], $user->id);

    expect((int) $destination->vessel_id)->toBe((int) $vesselB->id)
        ->and((int) $destination->client_id)->toBe((int) $nmdc->id)
        ->and((int) $assignment->fresh()->client_id)->toBe((int) $adnoc->id)
        ->and($assignment->fresh()->status)->toBe(CrewAssignmentStatus::Completed);
});

test('redeploy destination client matches destination vessel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $service = app(CrewMovementService::class);

    $adnoc = Client::query()->create(['name' => 'ADNOC Redeploy', 'is_active' => true]);
    $nmdc = Client::query()->create(['name' => 'NMDC Redeploy', 'is_active' => true]);

    $vesselA = makeCrewMovementVessel('Redeploy A', $company);
    $vesselA->update(['client_id' => $adnoc->id]);
    $vesselB = makeCrewMovementVessel('Redeploy B', $company);
    $vesselB->update(['client_id' => $nmdc->id]);

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vesselA, [
        'client_id' => $adnoc->id,
    ]);

    $service->perform($company->id, $assignment->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-02-01 08:00:00',
        'next_phase' => CrewPhaseCode::HomeRedeploy->value,
    ], $user->id);

    $destination = $service->perform($company->id, $assignment->id, CrewMovementAction::Redeploy, [
        'occurred_at' => '2026-02-02 08:00:00',
        'starting_phase' => CrewPhaseCode::OnVessel->value,
        'vessel_id' => $vesselB->id,
        'rank_id' => $rank->id,
    ], $user->id);

    expect((int) $destination->vessel_id)->toBe((int) $vesselB->id)
        ->and((int) $destination->client_id)->toBe((int) $nmdc->id);

    $assignment2Employee = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);
    $assignment2 = makeActiveOnVesselAssignment($company, $assignment2Employee, $rank, $vesselA, [
        'client_id' => $adnoc->id,
    ]);
    $service->perform($company->id, $assignment2->id, CrewMovementAction::ConfirmDisembarkation, [
        'occurred_at' => '2026-02-01 08:00:00',
        'next_phase' => CrewPhaseCode::HomeRedeploy->value,
    ], $user->id);

    expect(fn () => $service->perform($company->id, $assignment2->id, CrewMovementAction::Redeploy, [
        'occurred_at' => '2026-02-02 08:00:00',
        'starting_phase' => CrewPhaseCode::OnVessel->value,
        'vessel_id' => $vesselB->id,
        'rank_id' => $rank->id,
        'client_id' => $adnoc->id,
    ], $user->id))->toThrow(CrewMovementException::class);
});

test('legacy unassigned vessel cannot be used for new crew assignment or draft', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);

    $client = Client::query()->create(['name' => 'Ops Client', 'is_active' => true]);
    $vessel = makeCrewMovementVessel('Legacy Unassigned '.uniqid(), $company);
    $vessel->update(['client_id' => null]);
    expect($vessel->fresh()->client_id)->toBeNull();

    $this->post('/organization/crew', [
        'employee_id' => $employee->id,
        'rank_id' => $rank->id,
        'client_id' => $client->id,
        'vessel_id' => $vessel->id,
    ])->assertSessionHasErrors('vessel_id');

    $service = app(CrewMovementService::class);

    expect(fn () => $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'client_id' => $client->id,
    ], $user->id))->toThrow(CrewMovementException::class);
});

test('empty pre-mobilisation draft without vessel remains allowed', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);

    expect($assignment->vessel_id)->toBeNull()
        ->and($assignment->client_id)->toBeNull()
        ->and($assignment->status)->toBe(CrewAssignmentStatus::Draft);
});

test('join and transfer reject legacy unassigned destination vessel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $service = app(CrewMovementService::class);

    $client = Client::query()->create(['name' => 'Join Client', 'is_active' => true]);
    $mapped = makeCrewMovementVessel('Mapped Join', $company);
    $mapped->update(['client_id' => $client->id]);
    $unassigned = makeCrewMovementVessel('Unassigned Join', $company);
    $unassigned->update(['client_id' => null]);

    $draft = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'client_id' => $client->id,
    ], $user->id);

    expect(fn () => $service->perform($company->id, $draft->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-02-01 08:00:00',
        'vessel_id' => $unassigned->id,
        'rank_id' => $rank->id,
        'client_id' => $client->id,
    ], $user->id))->toThrow(CrewMovementException::class);

    $transferEmployee = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);
    $onVessel = makeActiveOnVesselAssignment($company, $transferEmployee, $rank, $mapped, [
        'client_id' => $client->id,
    ]);

    expect(fn () => $service->perform($company->id, $onVessel->id, CrewMovementAction::TransferVessel, [
        'occurred_at' => '2026-02-02 08:00:00',
        'vessel_id' => $unassigned->id,
        'rank_id' => $rank->id,
    ], $user->id))->toThrow(CrewMovementException::class);
});

test('crew planning conversion snapshots vessel client onto assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.create',
        'crew_operations.planning.update',
        'crew_operations.planning.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.view',
    ]);

    $client = Client::query()->create(['name' => 'Planning Client', 'is_active' => true]);
    $vessel = makeCrewMovementVessel('Planning Vessel '.uniqid(), $company);
    $vessel->update(['client_id' => $client->id]);

    $this->post('/organization/crew-planning/assignments', [
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-03-01',
        'planned_leave_date' => '2026-04-01',
    ])->assertRedirect();

    $planning = CrewPlanningAssignment::query()
        ->where('company_id', $company->id)
        ->where('vessel_id', $vessel->id)
        ->where('employee_id', $employee->id)
        ->first();

    expect($planning)->not->toBeNull();

    $assignment = app(CreateCrewAssignmentFromPlanning::class)
        ->handle($planning, $user->id);

    expect((int) $assignment->vessel_id)->toBe((int) $vessel->id)
        ->and((int) $assignment->client_id)->toBe((int) $client->id);
});

test('crew planning rejects legacy unassigned vessel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.create',
        'crew_operations.planning.view',
    ]);

    $vessel = makeCrewMovementVessel('Planning Unassigned '.uniqid(), $company);
    $vessel->update(['client_id' => null]);

    $this->post('/organization/crew-planning/assignments', [
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-03-01',
        'planned_leave_date' => '2026-04-01',
    ])->assertSessionHasErrors('vessel_id');
});

test('changing vessel current client does not rewrite historical assignment snapshot', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $adnoc = Client::query()->create(['name' => 'Hist ADNOC', 'is_active' => true]);
    $nmdc = Client::query()->create(['name' => 'Hist NMDC', 'is_active' => true]);
    $vessel = makeCrewMovementVessel('Hist Vessel', $company);
    $vessel->update(['client_id' => $adnoc->id]);

    $assignment = makeActiveOnVesselAssignment($company, $employee, $rank, $vessel, [
        'client_id' => $adnoc->id,
    ]);

    $vessel->update(['client_id' => $nmdc->id]);

    expect((int) $assignment->fresh()->client_id)->toBe((int) $adnoc->id)
        ->and((int) $vessel->fresh()->client_id)->toBe((int) $nmdc->id);
});

test('employee partial update compares project against persisted client', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.update',
    ]);

    $clientA = Client::query()->create(['name' => 'Partial A', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Partial B', 'is_active' => true]);
    $projectA = Project::query()->create(['title' => 'Partial Proj A', 'client_id' => $clientA->id, 'is_active' => true]);
    $projectB = Project::query()->create(['title' => 'Partial Proj B', 'client_id' => $clientB->id, 'is_active' => true]);

    $employee->update([
        'client_id' => $clientA->id,
        'project_id' => $projectA->id,
    ]);

    $this->put("/organization/employees/{$employee->id}", [
        'name' => $employee->name,
        'project_id' => $projectB->id,
    ])->assertSessionHasErrors('project_id');

    $this->put("/organization/employees/{$employee->id}", [
        'name' => $employee->name,
        'client_id' => $clientB->id,
    ])->assertSessionHasErrors('project_id');

    expect((int) $employee->fresh()->client_id)->toBe((int) $clientA->id)
        ->and((int) $employee->fresh()->project_id)->toBe((int) $projectA->id);
});

test('employee csv import rejects mismatched client and project pairs', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Import Template '.uniqid(),
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.import',
        'employees.update',
    ]);

    $clientA = Client::query()->create(['name' => 'Import Emp A', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Import Emp B', 'is_active' => true]);
    $projectA = Project::query()->create(['title' => 'Import Emp Proj A', 'client_id' => $clientA->id, 'is_active' => true]);
    $projectB = Project::query()->create(['title' => 'Import Emp Proj B', 'client_id' => $clientB->id, 'is_active' => true]);

    Employee::factory()->forCompany($company)->create([
        'employee_no' => 'EMP-CLI-1',
        'name' => 'Existing Import',
        'client_id' => $clientA->id,
        'project_id' => $projectA->id,
        'status' => 'active',
        'employee_profile_template_id' => $template->id,
    ]);

    $createCsv = "employee_no,name,client,project\nEMP-NEW-1,New Mismatch,Import Emp A,Import Emp Proj B\n";
    $createFile = UploadedFile::fake()->createWithContent('create.csv', $createCsv);

    $preview = $this->post('/organization/employees/import/preview', [
        'file' => $createFile,
        'employee_profile_template_id' => $template->id,
    ])->assertOk()->json();

    expect(collect($preview['errors'])->pluck('message')->implode(' '))
        ->toContain('does not belong to the selected client');

    $updateCsv = "employee_no,name,project\nEMP-CLI-1,Existing Import,Import Emp Proj B\n";
    $updateFile = UploadedFile::fake()->createWithContent('update.csv', $updateCsv);

    $updatePreview = $this->post('/organization/employees/import/preview', [
        'file' => $updateFile,
        'employee_profile_template_id' => $template->id,
    ])->assertOk()->json();

    expect(collect($updatePreview['errors'])->pluck('message')->implode(' '))
        ->toContain('does not belong to the selected client');

    $validCsv = "employee_no,name,client,project\nEMP-OK-1,Valid Pair,Import Emp A,Import Emp Proj A\n";
    $validFile = UploadedFile::fake()->createWithContent('valid.csv', $validCsv);

    $this->post('/organization/employees/import', [
        'file' => $validFile,
        'employee_profile_template_id' => $template->id,
    ])->assertRedirect('/organization/employees');

    $imported = Employee::query()
        ->where('company_id', $company->id)
        ->where('employee_no', 'EMP-OK-1')
        ->first();

    expect($imported)->not->toBeNull()
        ->and((int) $imported->client_id)->toBe((int) $clientA->id)
        ->and((int) $imported->project_id)->toBe((int) $projectA->id);
});

test('crew planning create rejects inactive mapped vessel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.create',
        'crew_operations.planning.view',
    ]);

    $client = Client::query()->create(['name' => 'Inactive Plan Client', 'is_active' => true]);
    $vessel = makeCrewMovementVessel('Inactive Plan Vessel', $company, $client);
    $vessel->update(['is_active' => false]);

    $this->post('/organization/crew-planning/assignments', [
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-03-01',
        'planned_leave_date' => '2026-04-01',
    ])->assertSessionHasErrors('vessel_id');
});

test('crew planning update rejects changing to inactive vessel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.create',
        'crew_operations.planning.update',
        'crew_operations.planning.view',
    ]);

    $client = Client::query()->create(['name' => 'Plan Update Client', 'is_active' => true]);
    $activeVessel = makeCrewMovementVessel('Active Plan Vessel', $company, $client);
    $inactiveVessel = makeCrewMovementVessel('Inactive Target Vessel', $company, $client);
    $inactiveVessel->update(['is_active' => false]);

    $this->post('/organization/crew-planning/assignments', [
        'vessel_id' => $activeVessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-03-01',
        'planned_leave_date' => '2026-04-01',
    ])->assertRedirect();

    $planning = CrewPlanningAssignment::query()
        ->where('company_id', $company->id)
        ->where('vessel_id', $activeVessel->id)
        ->firstOrFail();

    $this->put("/organization/crew-planning/assignments/{$planning->id}", [
        'vessel_id' => $inactiveVessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-03-01',
        'planned_leave_date' => '2026-04-01',
    ])->assertSessionHasErrors('vessel_id');
});

test('createDraft rejects inactive vessel directly', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $client = Client::query()->create(['name' => 'Draft Inactive Client', 'is_active' => true]);
    $vessel = makeCrewMovementVessel('Draft Inactive Vessel', $company, $client);
    $vessel->update(['is_active' => false]);

    expect(fn () => app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id))->toThrow(CrewMovementException::class);

    expect(CrewAssignment::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

test('planning conversion fails when vessel becomes inactive after planning', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $client = Client::query()->create(['name' => 'Convert Inactive Client', 'is_active' => true]);
    $vessel = makeCrewMovementVessel('Convert Inactive Vessel', $company, $client);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-03-01',
        'planned_leave_date' => '2026-04-01',
    ]);

    $vessel->update(['is_active' => false]);

    expect(fn () => app(CreateCrewAssignmentFromPlanning::class)->handle($planning, $user->id))
        ->toThrow(CrewMovementException::class);

    expect($planning->fresh()->crew_assignment_id)->toBeNull()
        ->and(CrewAssignment::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

test('editable legacy assignment can update remarks and planned date without changing vessel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.update',
    ]);

    $legacyVessel = makeCrewMovementVessel('Legacy Edit Vessel', $company);
    $legacyVessel->update(['client_id' => null]);

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'planned_join_at' => '2026-08-01',
        'remarks' => 'Original remarks',
    ], $user->id);

    $assignment->update([
        'vessel_id' => $legacyVessel->id,
        'client_id' => null,
    ]);

    $this->put(route('organization.crew-assignments.update', $assignment), [
        'rank_id' => $rank->id,
        'client_id' => null,
        'vessel_id' => $legacyVessel->id,
        'planned_join_at' => '2026-08-15',
        'planned_signoff_at' => null,
        'planned_travel_at' => null,
        'company_visa_type_id' => null,
        'remarks' => 'Travel confirmed',
    ])->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $fresh = $assignment->fresh();

    expect($fresh->remarks)->toBe('Travel confirmed')
        ->and($fresh->planned_join_at->toDateString())->toBe('2026-08-15')
        ->and((int) $fresh->vessel_id)->toBe((int) $legacyVessel->id)
        ->and($fresh->client_id)->toBeNull();
});

test('legacy assignment cannot change to another unassigned vessel or mismatched client', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.update',
    ]);

    $legacyA = makeCrewMovementVessel('Legacy A', $company);
    $legacyA->update(['client_id' => null]);
    $legacyB = makeCrewMovementVessel('Legacy B', $company);
    $legacyB->update(['client_id' => null]);
    $client = Client::query()->create(['name' => 'Legacy Mismatch Client', 'is_active' => true]);

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);
    $assignment->update(['vessel_id' => $legacyA->id, 'client_id' => null]);

    $this->put(route('organization.crew-assignments.update', $assignment), [
        'rank_id' => $rank->id,
        'client_id' => null,
        'vessel_id' => $legacyB->id,
        'remarks' => 'Swap legacy vessel',
    ])->assertSessionHasErrors('vessel_id');

    $this->put(route('organization.crew-assignments.update', $assignment), [
        'rank_id' => $rank->id,
        'client_id' => $client->id,
        'vessel_id' => $legacyA->id,
        'remarks' => 'Add client to legacy vessel',
    ])->assertSessionHasErrors('vessel_id');

    expect((int) $assignment->fresh()->vessel_id)->toBe((int) $legacyA->id)
        ->and($assignment->fresh()->client_id)->toBeNull();
});

test('legacy assignment can change to a valid active mapped vessel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.update',
    ]);

    $legacyVessel = makeCrewMovementVessel('Legacy Source', $company);
    $legacyVessel->update(['client_id' => null]);
    $clientB = Client::query()->create(['name' => 'Mapped Destination Client', 'is_active' => true]);
    $mappedVessel = makeCrewMovementVessel('Mapped Destination', $company, $clientB);

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);
    $assignment->update(['vessel_id' => $legacyVessel->id, 'client_id' => null]);

    $this->put(route('organization.crew-assignments.update', $assignment), [
        'rank_id' => $rank->id,
        'client_id' => $clientB->id,
        'vessel_id' => $mappedVessel->id,
        'remarks' => 'Moved to mapped vessel',
    ])->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $fresh = $assignment->fresh();

    expect((int) $fresh->vessel_id)->toBe((int) $mappedVessel->id)
        ->and((int) $fresh->client_id)->toBe((int) $clientB->id);
});

test('legacy assignment update still rejects cross-company vessel', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    ['company' => $otherCompany] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.update',
    ]);

    $legacyVessel = makeCrewMovementVessel('Legacy Local', $company);
    $legacyVessel->update(['client_id' => null]);
    $foreignVessel = makeCrewMovementVessel('Foreign Vessel', $otherCompany);

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
    ], $user->id);
    $assignment->update(['vessel_id' => $legacyVessel->id, 'client_id' => null]);

    $this->put(route('organization.crew-assignments.update', $assignment), [
        'rank_id' => $rank->id,
        'client_id' => null,
        'vessel_id' => $foreignVessel->id,
        'remarks' => 'Cross company attempt',
    ])->assertSessionHasErrors('vessel_id');

    expect((int) $assignment->fresh()->vessel_id)->toBe((int) $legacyVessel->id);
});

test('createDraft rejects active vessel whose client is inactive', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $client = Client::query()->create(['name' => 'Later Inactive Client', 'is_active' => true]);
    $vessel = makeCrewMovementVessel('Active Vessel Inactive Client', $company, $client);
    $client->update(['is_active' => false]);

    expect(fn () => app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
    ], $user->id))->toThrow(CrewMovementException::class);

    expect(CrewAssignment::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

test('crew planning create rejects active vessel whose client is inactive', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.planning.create',
        'crew_operations.planning.view',
    ]);

    $client = Client::query()->create(['name' => 'Planning Inactive Client', 'is_active' => true]);
    $vessel = makeCrewMovementVessel('Planning Vessel Inactive Client', $company, $client);
    $client->update(['is_active' => false]);

    $this->post('/organization/crew-planning/assignments', [
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-03-01',
        'planned_leave_date' => '2026-04-01',
    ])->assertSessionHasErrors('vessel_id');
});

test('planning conversion fails when vessel client becomes inactive after planning', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();

    $client = Client::query()->create(['name' => 'Convert Later Inactive', 'is_active' => true]);
    $vessel = makeCrewMovementVessel('Convert Later Inactive Vessel', $company, $client);

    $planning = CrewPlanningAssignment::query()->create([
        'company_id' => $company->id,
        'vessel_id' => $vessel->id,
        'rank_id' => $rank->id,
        'employee_id' => $employee->id,
        'planned_join_date' => '2026-03-01',
        'planned_leave_date' => '2026-04-01',
    ]);

    $client->update(['is_active' => false]);

    expect(fn () => app(CreateCrewAssignmentFromPlanning::class)->handle($planning, $user->id))
        ->toThrow(CrewMovementException::class);

    expect($planning->fresh()->crew_assignment_id)->toBeNull()
        ->and(CrewAssignment::query()->where('employee_id', $employee->id)->count())->toBe(0);
});

test('join and transfer reject active vessel whose current client is inactive', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $service = app(CrewMovementService::class);

    $client = Client::query()->create(['name' => 'Join Later Inactive', 'is_active' => true]);
    $mapped = makeCrewMovementVessel('Mapped Join Later Inactive', $company, $client);
    $destination = makeCrewMovementVessel('Destination Later Inactive', $company, $client);

    $draft = $service->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'client_id' => $client->id,
    ], $user->id);

    $client->update(['is_active' => false]);

    expect(fn () => $service->perform($company->id, $draft->id, CrewMovementAction::JoinVessel, [
        'occurred_at' => '2026-02-01 08:00:00',
        'vessel_id' => $mapped->id,
        'rank_id' => $rank->id,
        'client_id' => $client->id,
    ], $user->id))->toThrow(CrewMovementException::class);

    $client->update(['is_active' => true]);
    $transferEmployee = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);
    $onVessel = makeActiveOnVesselAssignment($company, $transferEmployee, $rank, $mapped, [
        'client_id' => $client->id,
    ]);
    $client->update(['is_active' => false]);

    expect(fn () => $service->perform($company->id, $onVessel->id, CrewMovementAction::TransferVessel, [
        'occurred_at' => '2026-02-02 08:00:00',
        'vessel_id' => $destination->id,
        'rank_id' => $rank->id,
        'client_id' => $client->id,
    ], $user->id))->toThrow(CrewMovementException::class);
});

test('inactive existing vessel with unchanged client allows remarks edit', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.update',
    ]);

    $client = Client::query()->create(['name' => 'Inactive Vessel Continuity Client', 'is_active' => true]);
    $vessel = makeCrewMovementVessel('Inactive Continuity Vessel', $company, $client);

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'client_id' => $client->id,
        'remarks' => 'Before',
    ], $user->id);

    $vessel->update(['is_active' => false]);

    $this->put(route('organization.crew-assignments.update', $assignment), [
        'rank_id' => $rank->id,
        'client_id' => $client->id,
        'vessel_id' => $vessel->id,
        'remarks' => 'After remarks',
    ])->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $fresh = $assignment->fresh();

    expect($fresh->remarks)->toBe('After remarks')
        ->and((int) $fresh->vessel_id)->toBe((int) $vessel->id)
        ->and((int) $fresh->client_id)->toBe((int) $client->id);
});

test('inactive existing vessel rejects client-only change', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.update',
    ]);

    $clientA = Client::query()->create(['name' => 'Client A Continuity', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Client B Continuity', 'is_active' => true]);
    $vessel = makeCrewMovementVessel('Inactive Client Change Vessel', $company, $clientA);

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'client_id' => $clientA->id,
    ], $user->id);

    $vessel->update([
        'is_active' => false,
        'client_id' => $clientB->id,
    ]);

    $this->put(route('organization.crew-assignments.update', $assignment), [
        'rank_id' => $rank->id,
        'client_id' => $clientB->id,
        'vessel_id' => $vessel->id,
        'remarks' => 'Client-only change on inactive vessel',
    ])->assertSessionHasErrors('vessel_id');

    expect((int) $assignment->fresh()->client_id)->toBe((int) $clientA->id)
        ->and((int) $assignment->fresh()->vessel_id)->toBe((int) $vessel->id);
});

test('existing inactive client snapshot survives remarks and planned date edits', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.update',
    ]);

    $client = Client::query()->create(['name' => 'Snapshot Inactive Client', 'is_active' => true]);
    $vessel = makeCrewMovementVessel('Snapshot Inactive Client Vessel', $company, $client);

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vessel->id,
        'client_id' => $client->id,
        'planned_join_at' => '2026-08-01',
        'remarks' => 'Original',
    ], $user->id);

    $client->update(['is_active' => false]);

    $this->put(route('organization.crew-assignments.update', $assignment), [
        'rank_id' => $rank->id,
        'client_id' => $client->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-08-01',
        'remarks' => 'Remarks only',
    ])->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $this->put(route('organization.crew-assignments.update', $assignment), [
        'rank_id' => $rank->id,
        'client_id' => $client->id,
        'vessel_id' => $vessel->id,
        'planned_join_at' => '2026-08-20',
        'remarks' => 'Remarks only',
    ])->assertRedirect(route('organization.crew-assignments.show', $assignment));

    $fresh = $assignment->fresh();

    expect((int) $fresh->client_id)->toBe((int) $client->id)
        ->and($fresh->remarks)->toBe('Remarks only')
        ->and($fresh->planned_join_at->toDateString())->toBe('2026-08-20');
});

test('cannot change crew assignment client to another inactive client', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.update',
    ]);

    $clientA = Client::query()->create(['name' => 'Active Snapshot Client', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Other Inactive Client', 'is_active' => false]);
    $vesselA = makeCrewMovementVessel('Active Snapshot Vessel', $company, $clientA);
    $vesselB = makeCrewMovementVessel('Other Inactive Client Vessel', $company);
    $vesselB->update(['client_id' => $clientB->id, 'is_active' => true]);

    $assignment = app(CrewMovementService::class)->createDraft($company->id, $employee->id, [
        'rank_id' => $rank->id,
        'vessel_id' => $vesselA->id,
        'client_id' => $clientA->id,
    ], $user->id);

    $this->put(route('organization.crew-assignments.update', $assignment), [
        'rank_id' => $rank->id,
        'client_id' => $clientB->id,
        'vessel_id' => $vesselB->id,
        'remarks' => 'Switch to inactive client',
    ])->assertSessionHasErrors('client_id');

    expect((int) $assignment->fresh()->client_id)->toBe((int) $clientA->id);
});

test('inactive existing vessel cannot become a new selection on another assignment', function () {
    ['user' => $user, 'company' => $company, 'employee' => $employee, 'rank' => $rank] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'crew_operations.assignments.view',
        'crew_operations.assignments.create',
        'crew_operations.assignments.update',
    ]);

    $client = Client::query()->create(['name' => 'New Selection Client', 'is_active' => true]);
    $inactiveVessel = makeCrewMovementVessel('New Selection Inactive Vessel', $company, $client);
    $inactiveVessel->update(['is_active' => false]);

    $otherEmployee = Employee::factory()->forCompany($company)->create([
        'rank_id' => $rank->id,
        'status' => 'active',
    ]);

    $this->post('/organization/crew', [
        'employee_id' => $otherEmployee->id,
        'rank_id' => $rank->id,
        'client_id' => $client->id,
        'vessel_id' => $inactiveVessel->id,
    ])->assertSessionHasErrors('vessel_id');

    expect(CrewAssignment::query()->where('employee_id', $otherEmployee->id)->count())->toBe(0);
});

test('active options requiring assigned client exclude vessels with inactive clients', function () {
    ['company' => $company] = makeCrewAssignmentFixtures();

    $activeClient = Client::query()->create(['name' => 'Options Active Client', 'is_active' => true]);
    $inactiveClient = Client::query()->create(['name' => 'Options Inactive Client', 'is_active' => true]);
    $good = makeCrewMovementVessel('Options Good Vessel', $company, $activeClient);
    $bad = makeCrewMovementVessel('Options Bad Vessel', $company, $inactiveClient);
    $inactiveClient->update(['is_active' => false]);

    $options = ResolvesCompanyVessels::activeOptions(
        (int) $company->id,
        requireAssignedClient: true,
    );

    expect(collect($options)->pluck('id')->all())
        ->toContain($good->id)
        ->not->toContain($bad->id);
});
