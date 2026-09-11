<?php

use App\Enums\CrewAssignmentStatus;
use App\Enums\CrewMovementAction;
use App\Enums\CrewPhaseCode;
use App\Exceptions\CrewMovementException;
use App\Models\Client;
use App\Models\CrewAssignment;
use App\Models\Employee;
use App\Models\Project;
use App\Support\CrewMovements\CrewMovementService;
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
