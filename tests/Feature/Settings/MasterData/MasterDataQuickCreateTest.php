<?php

use App\Models\Bank;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyVisaType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\Project;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Models\VisaType;
use App\Support\MasterData\MasterDataQuickCreate;

/**
 * @return array{user: User, company: Company}
 */
function quickCreateMasterDataUser(): array
{
    $user = User::factory()->create();

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

    return ['user' => $user, 'company' => $company];
}

test('json quick-create returns id and label for banks', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.banks.create',
    ]);

    $response = $this->postJson('/settings/master-data/banks', [
        'name' => 'SIB',
        'is_active' => true,
    ]);

    $response
        ->assertSuccessful()
        ->assertJson([
            'label' => 'SIB',
            'name' => 'SIB',
        ]);

    $id = $response->json('id');
    expect($id)->not->toBeNull();
    expect(Bank::query()->whereKey($id)->value('name'))->toBe('SIB');
});

test('json quick-create returns id and label for visa types', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.visa-types.create',
    ]);

    $this->postJson('/settings/master-data/visa-types', [
        'name' => 'Visit Visa',
        'is_active' => true,
    ])
        ->assertSuccessful()
        ->assertJson([
            'label' => 'Visit Visa',
            'name' => 'Visit Visa',
        ]);
});

test('json quick-create rejects duplicate visa type names with 422', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.visa-types.create',
    ]);

    VisaType::query()->create([
        'name' => 'Employment Visa',
        'is_active' => true,
    ]);

    $this->postJson('/settings/master-data/visa-types', [
        'name' => 'Employment Visa',
        'is_active' => true,
    ])->assertUnprocessable();
});

test('json quick-create returns existing visa type when name matches case-insensitively', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.visa-types.create',
    ]);

    $existing = VisaType::query()->create([
        'name' => 'Golden Visa',
        'is_active' => true,
    ]);

    $this->postJson('/settings/master-data/visa-types', [
        'name' => 'golden visa',
        'is_active' => true,
    ])
        ->assertSuccessful()
        ->assertJson([
            'id' => $existing->id,
            'label' => 'Golden Visa',
        ]);

    expect(VisaType::query()->count())->toBe(1);
});

test('json quick-create returns id and label for company visa types', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.company-visa-types.create',
    ]);

    $this->postJson('/settings/master-data/company-visa-types', [
        'name' => 'Company Visit Visa',
        'is_active' => true,
    ])
        ->assertSuccessful()
        ->assertJson([
            'label' => 'Company Visit Visa',
            'name' => 'Company Visit Visa',
        ]);
});

test('json quick-create rejects duplicate company visa type names with 422', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.company-visa-types.create',
    ]);

    CompanyVisaType::query()->create([
        'name' => 'Company Employment Visa',
        'is_active' => true,
    ]);

    $this->postJson('/settings/master-data/company-visa-types', [
        'name' => 'Company Employment Visa',
        'is_active' => true,
    ])->assertUnprocessable();
});

test('json quick-create returns existing company visa type when name matches case-insensitively', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.company-visa-types.create',
    ]);

    $existing = CompanyVisaType::query()->create([
        'name' => 'Company Golden Visa',
        'is_active' => true,
    ]);

    $this->postJson('/settings/master-data/company-visa-types', [
        'name' => 'company golden visa',
        'is_active' => true,
    ])
        ->assertSuccessful()
        ->assertJson([
            'id' => $existing->id,
            'label' => 'Company Golden Visa',
        ]);

    expect(CompanyVisaType::query()->count())->toBe(1);
});

test('json quick-create returns id and title for document types', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.document-types.create',
    ]);

    $this->postJson('/settings/master-data/document-types', [
        'title' => 'Seaman Book',
        'is_active' => true,
    ])
        ->assertSuccessful()
        ->assertJson([
            'label' => 'Seaman Book',
            'title' => 'Seaman Book',
        ]);

    expect(DocumentType::query()->where('title', 'Seaman Book')->exists())->toBeTrue();
});

test('json quick-create returns id and label for vessels with vessel type context', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    $vesselType = VesselType::query()->create([
        'name' => 'AHTS',
        'is_active' => true,
    ]);
    $client = Client::query()->create([
        'name' => 'Quick Create Client',
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, [
        'crew_operations.vessels.create',
    ]);

    $this->postJson(route('organization.vessels.store'), [
        'name' => 'MV Horizon',
        'client_id' => $client->id,
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ])
        ->assertSuccessful()
        ->assertJson([
            'label' => 'MV Horizon',
            'name' => 'MV Horizon',
        ]);

    expect(Vessel::query()
        ->where('company_id', $company->id)
        ->where('name', 'MV Horizon')
        ->where('vessel_type_id', $vesselType->id)
        ->exists())->toBeTrue();
});

test('json quick-create returns id and label for departments', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'departments.create',
    ]);

    $this->postJson('/organization/departments', [
        'name' => 'Engineering',
        'status' => 'active',
    ])
        ->assertSuccessful()
        ->assertJson([
            'label' => 'Engineering',
            'name' => 'Engineering',
        ]);

    expect(Department::query()
        ->where('company_id', $company->id)
        ->where('name', 'Engineering')
        ->exists())->toBeTrue();
});

test('non-json bank store still redirects to index', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.banks.create',
    ]);

    $this->post('/settings/master-data/banks', [
        'name' => 'Redirect Bank',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.banks.index'));
});

test('json quick-create requires client create permission', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.banks.create',
    ]);

    $this->postJson('/settings/master-data/clients', [
        'name' => 'Unauthorized Client',
        'is_active' => true,
    ])->assertForbidden();
});

test('json quick-create creates client and reuses case-insensitive trimmed duplicates', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.create',
    ]);

    $first = $this->postJson('/settings/master-data/clients', [
        'name' => '  Acme Marine  ',
        'is_active' => true,
    ])->assertSuccessful();

    expect(Client::query()->where('name', 'Acme Marine')->count())->toBe(1);

    $second = $this->postJson('/settings/master-data/clients', [
        'name' => 'acme marine',
        'is_active' => true,
    ])->assertSuccessful();

    expect($second->json('id'))->toBe($first->json('id'))
        ->and(Client::query()->whereRaw('LOWER(name) = ?', ['acme marine'])->count())->toBe(1);
});

test('json quick-create requires project create permission', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    $client = Client::query()->create(['name' => 'Project Perm Client', 'is_active' => true]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.create',
    ]);

    $this->postJson('/settings/master-data/projects', [
        'title' => 'Unauthorized Project',
        'client_ids' => [$client->id],
        'is_active' => true,
    ])->assertForbidden();
});

test('json quick-create attaches new project to selected client and reuses same title', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.create',
        'settings.master-data.projects.update',
    ]);

    $clientA = Client::query()->create(['name' => 'QC Client A', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'QC Client B', 'is_active' => true]);

    $created = $this->postJson('/settings/master-data/projects', [
        'title' => '  Shared Dock  ',
        'client_ids' => [$clientA->id],
        'is_active' => true,
    ])->assertSuccessful();

    $projectId = (int) $created->json('id');
    $project = Project::query()->findOrFail($projectId);

    expect($project->title)->toBe('Shared Dock')
        ->and($project->clients()->pluck('clients.id')->all())->toBe([$clientA->id]);

    $reuse = $this->postJson('/settings/master-data/projects', [
        'title' => 'shared dock',
        'client_ids' => [$clientA->id],
        'is_active' => true,
    ])->assertSuccessful();

    expect($reuse->json('id'))->toBe($projectId);

    $this->postJson('/settings/master-data/projects', [
        'title' => 'Shared Dock',
        'client_ids' => [$clientB->id],
        'is_active' => true,
    ])->assertSuccessful();

    expect($project->fresh()->clients()->pluck('clients.id')->sort()->values()->all())
        ->toBe([$clientA->id, $clientB->id]);
});

test('client quick-create rejects inactive duplicate with 422', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.create',
    ]);

    Client::query()->create([
        'name' => 'Inactive Marine',
        'is_active' => false,
    ]);

    $this->postJson('/settings/master-data/clients', [
        'name' => 'inactive marine',
        'is_active' => true,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('client quick-create rejects soft-deleted duplicate with 422', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.clients.create',
    ]);

    $deleted = Client::query()->create([
        'name' => 'Deleted Marine',
        'is_active' => true,
    ]);
    $deleted->delete();

    $this->postJson('/settings/master-data/clients', [
        'name' => 'Deleted Marine',
        'is_active' => true,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('project quick-create rejects inactive duplicate with 422', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    $client = Client::query()->create(['name' => 'Project Client', 'is_active' => true]);
    Project::query()->create([
        'title' => 'Inactive Dock',
        'is_active' => false,
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.create',
    ]);

    $this->postJson('/settings/master-data/projects', [
        'title' => 'inactive dock',
        'client_ids' => [$client->id],
        'is_active' => true,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title']);
});

test('project quick-create rejects soft-deleted duplicate with 422', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    $client = Client::query()->create(['name' => 'Deleted Project Client', 'is_active' => true]);
    $project = Project::query()->create([
        'title' => 'Deleted Dock',
        'is_active' => true,
    ]);
    $project->delete();

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.create',
    ]);

    $this->postJson('/settings/master-data/projects', [
        'title' => 'Deleted Dock',
        'client_ids' => [$client->id],
        'is_active' => true,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title']);
});

test('project quick-create rejects inactive selected client', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    $client = Client::query()->create(['name' => 'Inactive Client', 'is_active' => false]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.create',
    ]);

    $this->postJson('/settings/master-data/projects', [
        'title' => 'Dock',
        'client_ids' => [$client->id],
        'is_active' => true,
    ])->assertUnprocessable();
});

test('project quick-create rejects existing title not linked to selected client without update permission', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.projects.create',
    ]);

    $clientA = Client::query()->create(['name' => 'Client A', 'is_active' => true]);
    $clientB = Client::query()->create(['name' => 'Client B', 'is_active' => true]);
    $project = Project::query()->create(['title' => 'Shared Dock', 'is_active' => true]);
    $project->clients()->sync([$clientA->id]);

    $this->postJson('/settings/master-data/projects', [
        'title' => 'Shared Dock',
        'client_ids' => [$clientB->id],
        'is_active' => true,
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title']);
});

test('master data quick-create validation messages match conventions', function () {
    expect(MasterDataQuickCreate::CLIENT_INACTIVE_MESSAGE)->toContain('inactive')
        ->and(MasterDataQuickCreate::CLIENT_DELETED_MESSAGE)->toContain('deleted')
        ->and(MasterDataQuickCreate::PROJECT_NOT_LINKED_MESSAGE)->toContain('selected client');
});
