<?php

use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\SavedView;
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
            ->component('organization/documents/overview')
            ->where('summary.total_documents', 1)
            ->has('attention')
            ->has('compliance_types')
            ->missing('employees'));

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
    $this->get(route('organization.documents.configuration'))->assertForbidden();
});

test('bulk documents view users can open generate templates and activity but not requests', function () {
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

    $this->get(route('organization.documents.requests'))->assertForbidden();

    $this->get(route('organization.documents.requests', [
        'tab' => 'signatures',
    ]))->assertForbidden();

    $this->get(route('organization.documents.activity'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/bulk/index')
            ->where('view', 'history'));

    $this->get(route('organization.documents.templates'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/templates')
            ->has('system_templates', 1)
            ->where('system_templates.0.key', 'salary_certificate')
            ->where('can.document_types', false)
            ->where('can.generate', false));

    $this->get(route('organization.documents.configuration'))->assertForbidden();
});

test('legacy bulk urls redirect to current documents destinations', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);
    Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $this->get(route('organization.documents.bulk'))
        ->assertRedirect(route('organization.documents.generate'));

    $this->get(route('organization.documents.bulk', ['view' => 'signatures']))
        ->assertRedirect(route('organization.documents.generate'));

    $this->get(route('organization.documents.bulk', ['view' => 'history']))
        ->assertRedirect(route('organization.documents.activity'));
});

test('legacy bulk signatures url redirects to requests when current signing access exists', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $company = setupBulkDocumentsCompany($user, [
        'bulk_documents.view',
        'documents.recipient-requests.view',
    ]);
    Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $this->get(route('organization.documents.bulk', ['view' => 'signatures']))
        ->assertRedirect(route('organization.documents.requests', ['tab' => 'recipient']));
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

test('respond-only users can open the recipient requests tab', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.recipient-requests.respond']);

    $this->get(route('organization.documents.requests'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/requests/index')
            ->where('tab', 'recipient')
            ->where('can.respond_recipient_requests', true)
            ->where('can.view_recipient_requests', false));

    $this->get(route('organization.documents.requests', ['tab' => 'recipient']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/requests/index')
            ->where('tab', 'recipient')
            ->missing('signature_payload')
            ->missing('can.view_signatures')
            ->missing('can.review_signatures'));

    $this->get(route('organization.documents.requests', ['tab' => 'review']))->assertForbidden();
    $this->get(route('organization.documents.requests', ['tab' => 'signatures']))
        ->assertRedirect(route('organization.documents.requests', ['tab' => 'recipient']));
});

test('approval viewers can open requests without recipient permissions', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.requests.view']);

    $this->get(route('organization.documents.requests'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/requests/index')
            ->where('tab', 'review')
            ->missing('signature_payload'));

    $this->get(route('organization.documents.requests', ['tab' => 'recipient']))->assertForbidden();
    $this->get(route('organization.documents.requests', ['tab' => 'signatures']))
        ->assertRedirect(route('organization.documents.requests', ['tab' => 'review']));
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
            ->where('can.generate', false));
});

test('platform-view-only users cannot open the template bridge', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);
    grantPlatformAccess($user);
    session(['current_company_id' => $company->id]);

    $this->get(route('organization.documents.templates'))
        ->assertForbidden();
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

test('library default saved view redirect stays on library', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);

    SavedView::factory()->create([
        'user_id' => $user->id,
        'company_id' => $company->id,
        'page_key' => 'documents',
        'name' => 'Expiring in 30 days',
        'filters' => ['expiry' => 'expiring_30', 'search' => 'visa'],
        'is_default' => true,
    ]);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents.library'))
        ->assertRedirect(route('organization.documents.library', [
            'search' => 'visa',
            'expiry' => 'expiring_30',
        ]));

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/overview'));
});

test('filtered overview urls redirect to library with supported query state', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents', [
            'search' => 'visa',
            'expiry' => 'expired',
            'requirement_status' => 'missing',
            'department_id' => '12',
            'page' => '2',
            'company_id' => '99',
            'foo' => 'bar',
        ]))
        ->assertRedirect(route('organization.documents.library', [
            'search' => 'visa',
            'expiry' => 'expired',
            'requirement_status' => 'missing',
            'department_id' => '12',
            'page' => '2',
        ]));

    $this->withSession(['current_company_id' => $company->id])
        ->get(route('organization.documents', [
            'foo' => 'bar',
            'company_id' => $company->id,
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/overview'));
});

test('overview summary stays scoped to the active company', function () {
    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    $this->actingAs($user);

    grantCompanyPermissions($user, $companyA, ['documents.view']);
    grantCompanyPermissions($user, $companyB, ['documents.view']);

    $employeeA = Employee::factory()->forCompany($companyA)->create([
        'name' => 'Alpha Overview Employee',
        'status' => 'active',
    ]);
    $employeeB = Employee::factory()->forCompany($companyB)->create([
        'name' => 'Beta Overview Employee',
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
        'expiry_date' => now()->subDay()->toDateString(),
        'status' => 'expired',
    ]);

    EmployeeDocument::query()->create([
        'company_id' => $companyB->id,
        'employee_id' => $employeeB->id,
        'document_type_id' => $documentType->id,
        'type' => 'other',
        'document_type' => (string) $documentType->id,
        'file_path' => 'employee-documents/b.pdf',
        'expiry_date' => now()->subDay()->toDateString(),
        'status' => 'expired',
    ]);

    $this->withSession(['current_company_id' => $companyA->id])
        ->get(route('organization.documents'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/overview')
            ->where('summary.total_documents', 1)
            ->where('summary.expired', 1)
            ->where('attention.0.query.expiry', 'expired'));
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
