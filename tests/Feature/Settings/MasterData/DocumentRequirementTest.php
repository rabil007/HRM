<?php

use App\Models\Department;
use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use App\Models\Position;
use App\Models\Project;
use App\Models\Rank;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

function documentRequirementPermissions(): array
{
    return [
        'settings.master-data.document-types.view',
        'settings.master-data.document-types.create',
        'settings.master-data.document-types.update',
        'settings.master-data.document-types.delete',
    ];
}

function actingAsDocumentTypeManager(): array
{
    test()->seed(PermissionsSeeder::class);

    $user = User::factory()->create();
    test()->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, documentRequirementPermissions());

    return compact('user', 'company', 'employee', 'passportType');
}

test('document types remain optional until a company requirement is saved', function () {
    ['passportType' => $passportType] = actingAsDocumentTypeManager();

    $this->get('/settings/master-data/document-types')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/document-types')
            ->has('document_types')
        );

    expect(DocumentRequirement::query()->where('document_type_id', $passportType->id)->exists())->toBeFalse();
});

test('requirement can apply to all employees', function () {
    ['company' => $company, 'passportType' => $passportType] = actingAsDocumentTypeManager();

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => true,
        'is_required' => true,
        'required_for_all' => true,
    ])->assertRedirect('/settings/master-data/document-types');

    $requirement = DocumentRequirement::query()
        ->where('company_id', $company->id)
        ->where('document_type_id', $passportType->id)
        ->first();

    expect($requirement)->not->toBeNull()
        ->and($requirement->required_for_all)->toBeTrue()
        ->and($requirement->is_active)->toBeTrue();
});

test('requirement can persist multiple selected departments', function () {
    ['company' => $company, 'passportType' => $passportType] = actingAsDocumentTypeManager();

    $crew = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Crew',
        'code' => 'CRW',
        'status' => 'active',
    ]);
    $accounts = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Accounts',
        'code' => 'ACC',
        'status' => 'active',
    ]);

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => true,
        'is_required' => true,
        'required_for_all' => false,
        'department_ids' => [$crew->id, $accounts->id],
    ])->assertRedirect();

    $requirement = DocumentRequirement::query()
        ->where('company_id', $company->id)
        ->where('document_type_id', $passportType->id)
        ->first();

    expect($requirement->departments()->pluck('departments.id')->sort()->values()->all())
        ->toBe([$crew->id, $accounts->id]);
});

test('requirement can apply to positions and ranks', function () {
    ['company' => $company, 'passportType' => $passportType] = actingAsDocumentTypeManager();

    $position = Position::query()->create([
        'company_id' => $company->id,
        'title' => 'Able Seaman',
        'status' => 'active',
    ]);
    $rank = Rank::query()->create([
        'name' => 'Captain Req '.uniqid(),
        'is_active' => true,
    ]);

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => true,
        'is_required' => true,
        'required_for_all' => false,
        'position_ids' => [$position->id],
        'rank_ids' => [$rank->id],
    ])->assertRedirect();

    $requirement = DocumentRequirement::query()
        ->where('company_id', $company->id)
        ->where('document_type_id', $passportType->id)
        ->with(['positions', 'ranks'])
        ->first();

    expect($requirement->positions->pluck('id')->all())->toBe([$position->id])
        ->and($requirement->ranks->pluck('id')->all())->toBe([$rank->id]);
});

test('switching a document type to optional keeps the previous scope selection', function () {
    ['company' => $company, 'passportType' => $passportType] = actingAsDocumentTypeManager();

    $crew = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Crew Optional Keep',
        'code' => 'COK',
        'status' => 'active',
    ]);

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => true,
        'is_required' => true,
        'required_for_all' => false,
        'department_ids' => [$crew->id],
    ])->assertRedirect();

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => true,
        'is_required' => false,
        'required_for_all' => false,
        'department_ids' => [],
        'position_ids' => [],
        'rank_ids' => [],
    ])->assertRedirect();

    $requirement = DocumentRequirement::query()
        ->where('company_id', $company->id)
        ->where('document_type_id', $passportType->id)
        ->first();

    expect($requirement->is_active)->toBeFalse()
        ->and($requirement->departments()->pluck('departments.id')->all())->toBe([$crew->id]);
});

test('requirement changes are written to the company activity log', function () {
    ['company' => $company, 'passportType' => $passportType] = actingAsDocumentTypeManager();

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => true,
        'is_required' => true,
        'required_for_all' => true,
    ])->assertRedirect();

    $requirement = DocumentRequirement::query()
        ->where('company_id', $company->id)
        ->where('document_type_id', $passportType->id)
        ->first();

    $this->assertDatabaseHas('activity_log', [
        'subject_type' => DocumentRequirement::class,
        'subject_id' => $requirement->id,
        'event' => 'updated',
        'description' => $passportType->title.': Optional → Required for all employees',
    ]);

    expect(Activity::query()
        ->where('subject_type', DocumentRequirement::class)
        ->where('subject_id', $requirement->id)
        ->count())->toBe(1);
});

test('requirement metadata-only changes write a single custom activity event', function () {
    ['company' => $company, 'passportType' => $passportType] = actingAsDocumentTypeManager();

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => true,
        'is_required' => true,
        'required_for_all' => true,
    ])->assertRedirect();

    $requirement = DocumentRequirement::query()
        ->where('company_id', $company->id)
        ->where('document_type_id', $passportType->id)
        ->first();

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => true,
        'is_required' => true,
        'required_for_all' => true,
        'require_document_number' => true,
    ])->assertRedirect();

    expect(Activity::query()
        ->where('subject_type', DocumentRequirement::class)
        ->where('subject_id', $requirement->id)
        ->count())->toBe(2);

    $this->assertDatabaseHas('activity_log', [
        'subject_type' => DocumentRequirement::class,
        'subject_id' => $requirement->id,
        'event' => 'updated',
        'description' => $passportType->title.': required information updated',
    ]);
});

test('requirement metadata and scopes persist when the document type is deactivated and reactivated', function () {
    ['company' => $company, 'passportType' => $passportType] = actingAsDocumentTypeManager();

    $crew = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Crew Persist',
        'code' => 'CRP',
        'status' => 'active',
    ]);

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => true,
        'is_required' => true,
        'required_for_all' => false,
        'department_ids' => [$crew->id],
        'require_issue_date' => true,
        'require_expiry_date' => true,
        'require_document_number' => true,
    ])->assertRedirect();

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => false,
    ])->assertRedirect();

    $requirement = DocumentRequirement::query()
        ->where('company_id', $company->id)
        ->where('document_type_id', $passportType->id)
        ->first();

    expect($passportType->fresh()->is_active)->toBeFalse()
        ->and($requirement->is_active)->toBeTrue()
        ->and($requirement->require_issue_date)->toBeTrue()
        ->and($requirement->departments()->pluck('departments.id')->all())->toBe([$crew->id]);

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => true,
    ])->assertRedirect();

    expect($requirement->fresh()->departments()->pluck('departments.id')->all())->toBe([$crew->id]);
});

test('quick active toggle does not erase requirement configuration', function () {
    ['company' => $company, 'passportType' => $passportType] = actingAsDocumentTypeManager();

    $crew = Department::query()->create([
        'company_id' => $company->id,
        'name' => 'Crew Toggle',
        'code' => 'CRT',
        'status' => 'active',
    ]);

    makeDocumentRequirement($company->id, $passportType->id, departmentIds: [$crew->id]);

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => false,
    ])->assertRedirect();

    $requirement = DocumentRequirement::query()
        ->where('company_id', $company->id)
        ->where('document_type_id', $passportType->id)
        ->first();

    expect($requirement->is_active)->toBeTrue()
        ->and($requirement->departments()->pluck('departments.id')->all())->toBe([$crew->id]);
});

test('csv import does not erase unrelated requirement configuration', function () {
    ['company' => $company, 'passportType' => $passportType] = actingAsDocumentTypeManager();

    makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);

    $csvContent = "title,is_active\n{$passportType->title},yes\n";

    $this->post('/settings/master-data/document-types/import', [
        'file' => UploadedFile::fake()->createWithContent('types.csv', $csvContent),
    ])->assertRedirect('/settings/master-data/document-types');

    $requirement = DocumentRequirement::query()
        ->where('company_id', $company->id)
        ->where('document_type_id', $passportType->id)
        ->first();

    expect($requirement->required_for_all)->toBeTrue()
        ->and($requirement->is_active)->toBeTrue();
});

test('company a cannot attach company b department or position ids', function () {
    $this->seed(PermissionsSeeder::class);

    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    $this->actingAs($user);
    grantCompanyPermissions($user, $companyA, documentRequirementPermissions());

    $passportType = DocumentType::query()->firstOrCreate(
        ['title' => 'Passport Copy'],
        ['is_active' => true],
    );

    $foreignDepartment = Department::query()->create([
        'company_id' => $companyB->id,
        'name' => 'Foreign Crew',
        'code' => 'FCR',
        'status' => 'active',
    ]);
    $foreignPosition = Position::query()->create([
        'company_id' => $companyB->id,
        'title' => 'Foreign Position',
        'status' => 'active',
    ]);

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => true,
        'is_required' => true,
        'required_for_all' => false,
        'department_ids' => [$foreignDepartment->id],
        'position_ids' => [$foreignPosition->id],
    ])->assertSessionHasErrors(['department_ids.0', 'position_ids.0']);
});

test('the same document type can have different requirement rules per company', function () {
    $this->seed(PermissionsSeeder::class);

    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    $this->actingAs($user);

    $passportType = DocumentType::query()->firstOrCreate(
        ['title' => 'Passport Copy'],
        ['is_active' => true],
    );

    grantCompanyPermissions($user, $companyA, documentRequirementPermissions());
    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => true,
        'is_required' => true,
        'required_for_all' => true,
    ])->assertRedirect();

    grantCompanyPermissions($user, $companyB, documentRequirementPermissions());
    $this->withSession(['current_company_id' => $companyB->id])
        ->put("/settings/master-data/document-types/{$passportType->id}", [
            'title' => $passportType->title,
            'is_active' => true,
            'is_required' => true,
            'required_for_all' => false,
            'department_ids' => [
                Department::query()->create([
                    'company_id' => $companyB->id,
                    'name' => 'Beta Crew',
                    'code' => 'BCR',
                    'status' => 'active',
                ])->id,
            ],
        ])->assertRedirect();

    expect(DocumentRequirement::query()->where('company_id', $companyA->id)->where('document_type_id', $passportType->id)->value('required_for_all'))->toBeTrue()
        ->and(DocumentRequirement::query()->where('company_id', $companyB->id)->where('document_type_id', $passportType->id)->value('required_for_all'))->toBeFalse();
});

test('updating a requirement while company a is active does not change company b policy', function () {
    $this->seed(PermissionsSeeder::class);

    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    $this->actingAs($user);

    $passportType = DocumentType::query()->firstOrCreate(
        ['title' => 'Passport Copy'],
        ['is_active' => true],
    );

    makeDocumentRequirement($companyB->id, $passportType->id, requiredForAll: true);

    grantCompanyPermissions($user, $companyA, documentRequirementPermissions());
    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => true,
        'is_required' => true,
        'required_for_all' => false,
        'department_ids' => [
            Department::query()->create([
                'company_id' => $companyA->id,
                'name' => 'Alpha Crew',
                'code' => 'ACR',
                'status' => 'active',
            ])->id,
        ],
    ])->assertRedirect();

    expect(DocumentRequirement::query()->where('company_id', $companyB->id)->where('document_type_id', $passportType->id)->value('required_for_all'))->toBeTrue()
        ->and(DocumentRequirement::query()->where('company_id', $companyA->id)->where('document_type_id', $passportType->id)->value('required_for_all'))->toBeFalse();
});

test('users without update permission cannot mutate requirement configuration', function () {
    $this->seed(PermissionsSeeder::class);

    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['settings.master-data.document-types.view']);

    $this->get('/settings/master-data/document-types')->assertOk();

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => true,
        'is_required' => true,
        'required_for_all' => true,
    ])->assertForbidden();
});

test('selected groups require at least one scope', function () {
    ['passportType' => $passportType] = actingAsDocumentTypeManager();

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => true,
        'is_required' => true,
        'required_for_all' => false,
        'department_ids' => [],
        'position_ids' => [],
        'rank_ids' => [],
        'project_ids' => [],
    ])->assertSessionHasErrors('required_for_all');
});

test('requirement can apply to a selected project', function () {
    ['company' => $company, 'passportType' => $passportType] = actingAsDocumentTypeManager();

    $project = Project::query()->create([
        'title' => 'ADNOC Req '.uniqid(),
        'is_active' => true,
    ]);

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => true,
        'is_required' => true,
        'required_for_all' => false,
        'project_ids' => [$project->id],
    ])->assertRedirect();

    $requirement = DocumentRequirement::query()
        ->where('company_id', $company->id)
        ->where('document_type_id', $passportType->id)
        ->first();

    expect($requirement->projects()->pluck('projects.id')->all())->toBe([$project->id]);
});

test('saved project ids are returned when editing a document type', function () {
    ['company' => $company, 'passportType' => $passportType] = actingAsDocumentTypeManager();

    $project = Project::query()->create([
        'title' => 'ADNOC Edit '.uniqid(),
        'is_active' => true,
    ]);
    makeDocumentRequirement($company->id, $passportType->id, projectIds: [$project->id]);

    $this->get('/settings/master-data/document-types')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/master-data/document-types')
            ->where('projects', fn ($projects) => collect($projects)->contains('id', $project->id))
            ->where('document_types', function ($types) use ($passportType, $project) {
                $match = collect($types)->firstWhere('id', $passportType->id);

                return is_array($match)
                    && ($match['requirement']['project_ids'] ?? null) === [$project->id];
            })
        );
});

test('invalid and soft-deleted project ids are rejected', function () {
    ['passportType' => $passportType] = actingAsDocumentTypeManager();

    $deleted = Project::query()->create([
        'title' => 'Deleted Project '.uniqid(),
        'is_active' => true,
    ]);
    $deleted->delete();

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => true,
        'is_required' => true,
        'required_for_all' => false,
        'project_ids' => [999999],
    ])->assertSessionHasErrors('project_ids.0');

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => true,
        'is_required' => true,
        'required_for_all' => false,
        'project_ids' => [$deleted->id],
    ])->assertSessionHasErrors('project_ids.0');
});

test('project pivot changes appear in a single document requirement activity row', function () {
    ['company' => $company, 'passportType' => $passportType] = actingAsDocumentTypeManager();

    $project = Project::query()->create([
        'title' => 'ADNOC Audit',
        'is_active' => true,
    ]);

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => true,
        'is_required' => true,
        'required_for_all' => false,
        'project_ids' => [$project->id],
    ])->assertRedirect();

    $requirement = DocumentRequirement::query()
        ->where('company_id', $company->id)
        ->where('document_type_id', $passportType->id)
        ->first();

    $this->assertDatabaseHas('activity_log', [
        'subject_type' => DocumentRequirement::class,
        'subject_id' => $requirement->id,
        'event' => 'updated',
        'description' => $passportType->title.': Optional → ADNOC Audit project',
    ]);

    expect(Activity::query()
        ->where('subject_type', DocumentRequirement::class)
        ->where('subject_id', $requirement->id)
        ->count())->toBe(1);
});

test('deactivating a project does not erase a saved project requirement', function () {
    ['company' => $company, 'passportType' => $passportType] = actingAsDocumentTypeManager();

    $project = Project::query()->create([
        'title' => 'Inactive Keep '.uniqid(),
        'is_active' => true,
    ]);

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => true,
        'is_required' => true,
        'required_for_all' => false,
        'project_ids' => [$project->id],
    ])->assertRedirect();

    $project->update(['is_active' => false]);

    $this->put("/settings/master-data/document-types/{$passportType->id}", [
        'title' => $passportType->title,
        'is_active' => false,
    ])->assertRedirect();

    $requirement = DocumentRequirement::query()
        ->where('company_id', $company->id)
        ->where('document_type_id', $passportType->id)
        ->first();

    expect($requirement->projects()->pluck('projects.id')->all())->toBe([$project->id]);
});
