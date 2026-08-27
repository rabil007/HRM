<?php

use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

test('documents view users can open overview and library but not generate routes', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'employee' => $employee, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);

    EmployeeDocument::query()->create([
        'company_id' => $company->id,
        'employee_id' => $employee->id,
        'document_type_id' => $passportType->id,
        'type' => 'other',
        'document_type' => (string) $passportType->id,
        'file_path' => 'employee-documents/test/a.pdf',
        'status' => 'valid',
    ]);

    $this->get(route('organization.documents'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/index')
            ->where('module_section', 'overview')
            ->has('employees', 1));

    $this->get(route('organization.documents.library'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/index')
            ->where('module_section', 'library')
            ->has('employees', 1));

    $this->get(route('organization.documents.generate'))->assertForbidden();
    $this->get(route('organization.documents.requests'))->assertForbidden();
    $this->get(route('organization.documents.activity'))->assertForbidden();
    $this->get(route('organization.documents.templates'))->assertForbidden();
});

test('bulk documents view users can open generate requests and activity', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);
    Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $this->get(route('organization.documents'))->assertForbidden();
    $this->get(route('organization.documents.library'))->assertForbidden();

    $this->get(route('organization.documents.generate'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/bulk/index')
            ->where('view', 'roster')
            ->has('employees', 1));

    $this->get(route('organization.documents.requests'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/bulk/index')
            ->where('view', 'signatures'));

    $this->get(route('organization.documents.activity'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/bulk/index')
            ->where('view', 'history'));

    $this->get(route('organization.documents.templates'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/templates')
            ->has('system_templates', 2)
            ->where('can.document_types', false)
            ->where('can.signature_placement', false));
});

test('legacy bulk urls still work and keep the matching module view', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);
    Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $this->get(route('organization.documents.bulk'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/bulk/index')
            ->where('view', 'roster'));

    $this->get(route('organization.documents.bulk', ['view' => 'signatures']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/bulk/index')
            ->where('view', 'signatures'));

    $this->get(route('organization.documents.bulk', ['view' => 'history']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/bulk/index')
            ->where('view', 'history'));
});

test('explicit generate route ignores a conflicting legacy view query', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $this->get(route('organization.documents.generate', ['view' => 'signatures']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/bulk/index')
            ->where('view', 'roster'));
});

test('unauthorized users cannot visit the unified documents routes', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.documents'))->assertForbidden();
    $this->get(route('organization.documents.library'))->assertForbidden();
    $this->get(route('organization.documents.generate'))->assertForbidden();
    $this->get(route('organization.documents.requests'))->assertForbidden();
    $this->get(route('organization.documents.activity'))->assertForbidden();
    $this->get(route('organization.documents.templates'))->assertForbidden();
});

test('template bridge only exposes links the user can access', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['settings.master-data.document-types.view']);

    $this->get(route('organization.documents.templates'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/templates')
            ->where('system_templates', [])
            ->where('can.document_types', true)
            ->where('can.signature_placement', false));
});

test('platform viewers can open the template bridge for signature placement', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);
    grantPlatformAccess($user);
    session(['current_company_id' => $company->id]);

    $this->get(route('organization.documents.templates'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/templates')
            ->where('system_templates', [])
            ->where('can.document_types', false)
            ->where('can.signature_placement', true));
});

test('library stays scoped to the active company', function () {
    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    $this->actingAs($user);

    grantCompanyPermissions($user, $companyA, ['documents.view']);
    grantCompanyPermissions($user, $companyB, ['documents.view']);

    $employeeA = Employee::factory()->forCompany($companyA)->create([
        'name' => 'Alpha Docs Employee',
        'status' => 'active',
    ]);
    $employeeB = Employee::factory()->forCompany($companyB)->create([
        'name' => 'Beta Docs Employee',
        'status' => 'active',
    ]);

    $documentType = DocumentType::query()->firstOrCreate(
        ['title' => 'Passport Copy'],
        ['is_active' => true],
    );

    EmployeeDocument::query()->create([
        'company_id' => $companyA->id,
        'employee_id' => $employeeA->id,
        'document_type_id' => $documentType->id,
        'type' => 'other',
        'document_type' => (string) $documentType->id,
        'file_path' => 'employee-documents/a.pdf',
        'status' => 'valid',
    ]);

    EmployeeDocument::query()->create([
        'company_id' => $companyB->id,
        'employee_id' => $employeeB->id,
        'document_type_id' => $documentType->id,
        'type' => 'other',
        'document_type' => (string) $documentType->id,
        'file_path' => 'employee-documents/b.pdf',
        'status' => 'valid',
    ]);

    $this->withSession(['current_company_id' => $companyA->id])
        ->get(route('organization.documents.library'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/index')
            ->has('employees', 1)
            ->where('employees.0.employee_id', $employeeA->id));
});

test('generate stays scoped to the active company', function () {
    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    $this->actingAs($user);

    grantCompanyPermissions($user, $companyA, ['bulk_documents.view']);
    grantCompanyPermissions($user, $companyB, ['bulk_documents.view']);

    Employee::factory()->forCompany($companyA)->create([
        'name' => 'Alpha Employee',
        'status' => 'active',
    ]);
    Employee::factory()->forCompany($companyB)->create([
        'name' => 'Beta Employee',
        'status' => 'active',
    ]);

    $this->withSession(['current_company_id' => $companyA->id])
        ->get(route('organization.documents.generate'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/bulk/index')
            ->where('company_name', $companyA->name)
            ->has('employees', 1)
            ->where('employees.0.name', 'Alpha Employee'));
});
