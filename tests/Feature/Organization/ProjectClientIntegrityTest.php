<?php

use App\Enums\Recruitment\RequirementLineStatus;
use App\Enums\Recruitment\RequirementStatus;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EmployeeProfileTemplate;
use App\Models\Position;
use App\Models\Project;
use App\Models\RecruitmentRequirement;
use App\Models\RecruitmentRequirementLine;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateFieldRegistry;
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

test('project import requires update permission to attach new clients to existing projects', function () {
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

    $existing = Project::query()->create([
        'title' => 'Import Existing',
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);

    // Create-only user cannot mutate existing projects
    $csv = "client,project,is_active\nImport Client A,Import Legacy,yes\nImport Client B,Import Existing,yes\n";

    $this->post('/settings/master-data/projects/import', [
        'file' => UploadedFile::fake()->createWithContent('projects.csv', $csv),
    ])->assertRedirect('/settings/master-data/projects');

    expect($legacy->fresh()->client_id)->toBeNull()
        ->and((int) $existing->fresh()->client_id)->toBe((int) $clientA->id)
        ->and($existing->fresh()->clients()->pluck('clients.id')->all())->toBe([$clientA->id]);

    // User with update permission can mutate existing projects and attach clients
    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.view',
        'settings.master-data.projects.create',
        'settings.master-data.projects.update',
    ]);

    $this->post('/settings/master-data/projects/import', [
        'file' => UploadedFile::fake()->createWithContent('projects.csv', $csv),
    ])->assertRedirect('/settings/master-data/projects');

    expect((int) $legacy->fresh()->client_id)->toBe((int) $clientA->id)
        ->and($existing->fresh()->clients()->pluck('clients.id')->sort()->values()->all())
        ->toBe([$clientA->id, $clientB->id]);
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

test('legacy null project mapping succeeds when employee already has destination client', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.update',
    ]);

    $clientA = Client::query()->create(['name' => 'Align Client A', 'is_active' => true]);
    $project = Project::query()->create([
        'title' => 'Align Project '.uniqid(),
        'client_id' => null,
        'is_active' => true,
    ]);

    Employee::factory()->forCompany($company)->create([
        'client_id' => $clientA->id,
        'project_id' => $project->id,
        'status' => 'active',
    ]);

    $this->put("/settings/master-data/projects/{$project->id}", [
        'title' => $project->title,
        'client_id' => $clientA->id,
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.projects.index'));

    expect((int) $project->fresh()->client_id)->toBe((int) $clientA->id);
});

test('legacy null project mapping rejects when employee has conflicting client', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.update',
    ]);

    $clientA = Client::query()->create(['name' => 'Conflict Client A', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Conflict Client B', 'is_active' => true]);
    $project = Project::query()->create([
        'title' => 'Conflict Project '.uniqid(),
        'client_id' => null,
        'is_active' => true,
    ]);

    Employee::factory()->forCompany($company)->create([
        'client_id' => $clientB->id,
        'project_id' => $project->id,
        'status' => 'active',
    ]);

    $this->put("/settings/master-data/projects/{$project->id}", [
        'title' => $project->title,
        'client_id' => $clientA->id,
        'is_active' => true,
    ])->assertSessionHasErrors('client_id');

    expect($project->fresh()->client_id)->toBeNull();
});

test('legacy null project with only null-client employees can map to a client', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.update',
    ]);

    $clientA = Client::query()->create(['name' => 'Null Emp Client', 'is_active' => true]);
    $project = Project::query()->create([
        'title' => 'Null Emp Project '.uniqid(),
        'client_id' => null,
        'is_active' => true,
    ]);

    Employee::factory()->forCompany($company)->create([
        'client_id' => null,
        'project_id' => $project->id,
        'status' => 'active',
    ]);

    $this->put("/settings/master-data/projects/{$project->id}", [
        'title' => $project->title,
        'client_id' => $clientA->id,
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.projects.index'));

    expect((int) $project->fresh()->client_id)->toBe((int) $clientA->id);
});

test('recruitment requirement lifecycle guard blocks unlink across draft open completed cancelled but allows soft deleted', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.view',
        'settings.master-data.projects.update',
    ]);

    $clientA = Client::query()->create(['name' => 'Recruit Guard Client A', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Recruit Guard Client B', 'is_active' => true]);

    $project = Project::query()->create([
        'title' => 'Recruit Guarded Project '.uniqid(),
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);
    $project->clients()->sync([$clientA->id, $clientB->id]);

    $requirement = RecruitmentRequirement::query()->create([
        'company_id' => $company->id,
        'requirement_number' => 'REQ-GUARD-LIFECYCLE',
        'client_id' => $clientA->id,
        'project_id' => $project->id,
        'request_received_date' => now()->toDateString(),
        'required_by_date' => now()->addWeek()->toDateString(),
        'priority' => 'normal',
        'status' => RequirementStatus::Draft,
    ]);

    // 1. Draft blocks unlinking Client A
    $this->put("/settings/master-data/projects/{$project->id}", [
        'title' => $project->title,
        'client_ids' => [$clientB->id],
        'is_active' => true,
    ])->assertSessionHasErrors('client_ids');

    expect($project->fresh()->clients()->pluck('clients.id')->sort()->values()->all())
        ->toBe([$clientA->id, $clientB->id]);

    // 2. Open blocks unlinking Client A
    $requirement->update(['status' => RequirementStatus::Open]);

    $this->put("/settings/master-data/projects/{$project->id}", [
        'title' => $project->title,
        'client_ids' => [$clientB->id],
        'is_active' => true,
    ])->assertSessionHasErrors('client_ids');

    // 3. Completed blocks unlinking Client A (retained business history)
    $requirement->update(['status' => RequirementStatus::Completed]);

    $this->put("/settings/master-data/projects/{$project->id}", [
        'title' => $project->title,
        'client_ids' => [$clientB->id],
        'is_active' => true,
    ])->assertSessionHasErrors('client_ids');

    // 4. Cancelled blocks unlinking Client A (retained business history and can be reopened)
    $requirement->update(['status' => RequirementStatus::Cancelled]);

    $this->put("/settings/master-data/projects/{$project->id}", [
        'title' => $project->title,
        'client_ids' => [$clientB->id],
        'is_active' => true,
    ])->assertSessionHasErrors('client_ids');

    // 5. Soft-deleted requirement intentionally removed from operational integrity -> unlinking allowed
    $requirement->delete();
    expect($requirement->fresh()->trashed())->toBeTrue();

    $this->put("/settings/master-data/projects/{$project->id}", [
        'title' => $project->title,
        'client_ids' => [$clientB->id],
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.projects.index'));

    expect($project->fresh()->clients()->pluck('clients.id')->all())
        ->toBe([$clientB->id]);
});

test('employee backend create and update allow multi-client project and reject unassigned pairs', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user)->withSession(['current_company_id' => $company->id]);

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.create',
        'employees.update',
    ]);

    $clientA = Client::query()->create(['name' => 'Multi Emp Client A '.uniqid(), 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Multi Emp Client B '.uniqid(), 'is_active' => true]);

    $project1 = Project::query()->create([
        'title' => 'Shared Project 1 '.uniqid(),
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);
    $project1->clients()->sync([$clientA->id, $clientB->id]);

    $project2 = Project::query()->create([
        'title' => 'Exclusive Project 2 '.uniqid(),
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);
    $project2->clients()->sync([$clientA->id]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Emp Template '.uniqid(),
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    // 1. Employee A + Project 1 succeeds
    $this->post('/organization/employees', [
        'employee_profile_template_id' => $template->id,
        'employee_no' => 'EMP-TEST-A1',
        'name' => 'Employee A1',
        'start_date' => '2026-01-01',
        'status' => 'active',
        'client_id' => $clientA->id,
        'project_id' => $project1->id,
    ])->assertRedirect('/organization/employees');

    $empA1 = Employee::query()->where('company_id', $company->id)->where('employee_no', 'EMP-TEST-A1')->first();
    expect($empA1)->not->toBeNull()
        ->and((int) $empA1->client_id)->toBe((int) $clientA->id)
        ->and((int) $empA1->project_id)->toBe((int) $project1->id);

    // 2. Employee B + Project 1 succeeds
    $this->post('/organization/employees', [
        'employee_profile_template_id' => $template->id,
        'employee_no' => 'EMP-TEST-B1',
        'name' => 'Employee B1',
        'start_date' => '2026-01-01',
        'status' => 'active',
        'client_id' => $clientB->id,
        'project_id' => $project1->id,
    ])->assertRedirect('/organization/employees');

    $empB1 = Employee::query()->where('company_id', $company->id)->where('employee_no', 'EMP-TEST-B1')->first();
    expect($empB1)->not->toBeNull()
        ->and((int) $empB1->client_id)->toBe((int) $clientB->id)
        ->and((int) $empB1->project_id)->toBe((int) $project1->id);

    // 3. Employee A + Project 2 succeeds
    $this->post('/organization/employees', [
        'employee_profile_template_id' => $template->id,
        'employee_no' => 'EMP-TEST-A2',
        'name' => 'Employee A2',
        'start_date' => '2026-01-01',
        'status' => 'active',
        'client_id' => $clientA->id,
        'project_id' => $project2->id,
    ])->assertRedirect('/organization/employees');

    $empA2 = Employee::query()->where('company_id', $company->id)->where('employee_no', 'EMP-TEST-A2')->first();
    expect($empA2)->not->toBeNull()
        ->and((int) $empA2->client_id)->toBe((int) $clientA->id)
        ->and((int) $empA2->project_id)->toBe((int) $project2->id);

    // 4. Employee B + Project 2 fails validation
    $this->post('/organization/employees', [
        'employee_profile_template_id' => $template->id,
        'employee_no' => 'EMP-TEST-B2',
        'name' => 'Employee B2',
        'start_date' => '2026-01-01',
        'status' => 'active',
        'client_id' => $clientB->id,
        'project_id' => $project2->id,
    ])->assertSessionHasErrors(['project_id' => 'The selected project is not assigned to the selected client.']);
});

test('employee client change preserves project when mapped to new client and rejects when unmapped', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user)->withSession(['current_company_id' => $company->id]);

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.update',
    ]);

    $clientA = Client::query()->create(['name' => 'Switch Client A '.uniqid(), 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Switch Client B '.uniqid(), 'is_active' => true]);
    $clientC = Client::query()->create(['name' => 'Switch Client C '.uniqid(), 'is_active' => true]);

    $project1 = Project::query()->create([
        'title' => 'Switch Project 1 '.uniqid(),
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);
    $project1->clients()->sync([$clientA->id, $clientB->id]);

    $employee = Employee::factory()->forCompany($company)->create([
        'client_id' => $clientA->id,
        'project_id' => $project1->id,
        'status' => 'active',
    ]);

    // Switch Client A -> Client B with Project 1 remains valid
    $this->put("/organization/employees/{$employee->id}", [
        'name' => $employee->name,
        'client_id' => $clientB->id,
        'project_id' => $project1->id,
    ])->assertRedirect(route('organization.employees.show', $employee));

    expect((int) $employee->fresh()->client_id)->toBe((int) $clientB->id)
        ->and((int) $employee->fresh()->project_id)->toBe((int) $project1->id);

    // Switch to Client C with Project 1 is rejected
    $this->put("/organization/employees/{$employee->id}", [
        'name' => $employee->name,
        'client_id' => $clientC->id,
        'project_id' => $project1->id,
    ])->assertSessionHasErrors(['project_id' => 'The selected project is not assigned to the selected client.']);

    // Persisted state remains unchanged
    expect((int) $employee->fresh()->client_id)->toBe((int) $clientB->id)
        ->and((int) $employee->fresh()->project_id)->toBe((int) $project1->id);
});

test('employee csv import allows shared project for mapped clients and rejects unmapped client', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user)->withSession(['current_company_id' => $company->id]);

    $template = EmployeeProfileTemplate::query()->create([
        'company_id' => $company->id,
        'name' => 'Shared Import Template '.uniqid(),
        'configuration_json' => EmployeeProfileTemplateFieldRegistry::defaultConfiguration(),
    ]);

    grantCompanyPermissions($user, $company, [
        'employees.view',
        'employees.import',
    ]);

    $clientA = Client::query()->create(['name' => 'Shared Imp Client A', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Shared Imp Client B', 'is_active' => true]);
    $clientC = Client::query()->create(['name' => 'Shared Imp Client C', 'is_active' => true]);

    $sharedProject = Project::query()->create([
        'title' => 'Shared Imp Proj',
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);
    $sharedProject->clients()->sync([$clientA->id, $clientB->id]);

    // 1. Preview with unmapped client C + shared project is rejected
    $invalidCsv = "employee_no,name,client,project\nEMP-IMP-C,Sam,Shared Imp Client C,Shared Imp Proj\n";
    $invalidFile = UploadedFile::fake()->createWithContent('invalid.csv', $invalidCsv);

    $preview = $this->post('/organization/employees/import/preview', [
        'file' => $invalidFile,
        'employee_profile_template_id' => $template->id,
    ])->assertOk()->json();

    expect(collect($preview['errors'])->pluck('message')->implode(' '))
        ->toContain('The selected project is not assigned to the selected client.');

    // 2. Import valid rows for Client A and Client B sharing the same project succeeds
    $validCsv = "employee_no,name,client,project\nEMP-IMP-A,John,Shared Imp Client A,Shared Imp Proj\nEMP-IMP-B,Ali,Shared Imp Client B,Shared Imp Proj\n";
    $validFile = UploadedFile::fake()->createWithContent('valid.csv', $validCsv);

    $this->post('/organization/employees/import', [
        'file' => $validFile,
        'employee_profile_template_id' => $template->id,
    ])->assertRedirect('/organization/employees');

    $empA = Employee::query()->where('company_id', $company->id)->where('employee_no', 'EMP-IMP-A')->first();
    $empB = Employee::query()->where('company_id', $company->id)->where('employee_no', 'EMP-IMP-B')->first();

    expect($empA)->not->toBeNull()
        ->and((int) $empA->client_id)->toBe((int) $clientA->id)
        ->and((int) $empA->project_id)->toBe((int) $sharedProject->id);

    expect($empB)->not->toBeNull()
        ->and((int) $empB->client_id)->toBe((int) $clientB->id)
        ->and((int) $empB->project_id)->toBe((int) $sharedProject->id);
});

test('recruitment requirement create and update allow multi-client project and reject unmapped client', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user)->withSession(['current_company_id' => $company->id]);

    grantCompanyPermissions($user, $company, [
        'recruitment.requirements.view',
        'recruitment.requirements.create',
        'recruitment.requirements.update',
    ]);

    $clientA = Client::query()->create(['name' => 'Recruit Client A '.uniqid(), 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Recruit Client B '.uniqid(), 'is_active' => true]);
    $clientC = Client::query()->create(['name' => 'Recruit Client C '.uniqid(), 'is_active' => true]);

    $project1 = Project::query()->create([
        'title' => 'Recruit Proj 1 '.uniqid(),
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);
    $project1->clients()->sync([$clientA->id, $clientB->id]);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Rigger '.uniqid(),
        'status' => 'active',
    ]);

    // 1. Create with Client A + Project 1 succeeds
    $this->post('/organization/recruitment/requirements', [
        'client_id' => $clientA->id,
        'project_id' => $project1->id,
        'priority' => 'normal',
        'request_received_date' => now()->toDateString(),
        'required_by_date' => now()->addMonth()->toDateString(),
        'lines' => [
            [
                'position_id' => $position->id,
                'required_headcount' => 2,
            ],
        ],
    ])->assertRedirect('/organization/recruitment/requirements');

    // 2. Create with Client B + Project 1 succeeds
    $this->post('/organization/recruitment/requirements', [
        'client_id' => $clientB->id,
        'project_id' => $project1->id,
        'priority' => 'normal',
        'request_received_date' => now()->toDateString(),
        'required_by_date' => now()->addMonth()->toDateString(),
        'lines' => [
            [
                'position_id' => $position->id,
                'required_headcount' => 1,
            ],
        ],
    ])->assertRedirect('/organization/recruitment/requirements');

    // 3. Create with Client C + Project 1 fails validation
    $this->post('/organization/recruitment/requirements', [
        'client_id' => $clientC->id,
        'project_id' => $project1->id,
        'priority' => 'normal',
        'request_received_date' => now()->toDateString(),
        'required_by_date' => now()->addMonth()->toDateString(),
        'lines' => [
            [
                'position_id' => $position->id,
                'required_headcount' => 1,
            ],
        ],
    ])->assertSessionHasErrors(['project_id' => 'The selected project is not assigned to the selected client.']);
});

test('recruitment duplicate detection treats shared project under different clients as distinct', function () {
    ['user' => $user, 'company' => $company] = makeCrewAssignmentFixtures();
    $this->actingAs($user)->withSession(['current_company_id' => $company->id]);

    grantCompanyPermissions($user, $company, [
        'recruitment.requirements.view',
        'recruitment.requirements.create',
    ]);

    $clientA = Client::query()->create(['name' => 'Dup Client A '.uniqid(), 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Dup Client B '.uniqid(), 'is_active' => true]);

    $project1 = Project::query()->create([
        'title' => 'Dup Shared Proj '.uniqid(),
        'client_id' => $clientA->id,
        'is_active' => true,
    ]);
    $project1->clients()->sync([$clientA->id, $clientB->id]);

    $position = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Deckhand '.uniqid(),
        'status' => 'active',
    ]);

    $reqA = RecruitmentRequirement::query()->create([
        'company_id' => $company->id,
        'requirement_number' => 'REQ-DUP-A',
        'client_id' => $clientA->id,
        'project_id' => $project1->id,
        'request_received_date' => now(),
        'required_by_date' => now()->addDays(14),
        'priority' => 'normal',
        'status' => RequirementStatus::Open,
        'created_by' => $user->id,
    ]);

    RecruitmentRequirementLine::query()->create([
        'company_id' => $company->id,
        'recruitment_requirement_id' => $reqA->id,
        'position_id' => $position->id,
        'required_headcount' => 2,
        'status' => RequirementLineStatus::Open,
    ]);

    // Checking similar for Client A + Project 1 + position detects duplicate
    $resA = $this->postJson('/organization/recruitment/requirements/check-similar', [
        'client_id' => $clientA->id,
        'project_id' => $project1->id,
        'position_ids' => [$position->id],
    ]);

    $resA->assertSuccessful()
        ->assertJson([
            'has_duplicates' => true,
        ]);

    // Checking similar for Client B + Project 1 + position does NOT detect duplicate (distinct client identity)
    $resB = $this->postJson('/organization/recruitment/requirements/check-similar', [
        'client_id' => $clientB->id,
        'project_id' => $project1->id,
        'position_ids' => [$position->id],
    ]);

    $resB->assertSuccessful()
        ->assertJson([
            'has_duplicates' => false,
        ]);
});
