<?php

use App\Models\User;
use App\Support\Documents\DocumentsModuleAccess;
use Inertia\Testing\AssertableInertia as Assert;

test('documents library section uses the folder index under the unified module', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);

    $this->get(route('organization.documents.library'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/index')
            ->where('section', 'library')
        );
});

test('documents overview keeps the existing documents index route', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);

    $this->get(route('organization.documents'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/index')
            ->where('section', 'overview')
        );
});

test('generate send requests and activity sections reuse bulk documents with forced views', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $this->get(route('organization.documents.generate'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/bulk/index')
            ->where('section', 'generate')
            ->where('view', 'roster')
        );

    $this->get(route('organization.documents.requests'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/bulk/index')
            ->where('section', 'requests')
            ->where('view', 'signatures')
        );

    $this->get(route('organization.documents.activity'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/bulk/index')
            ->where('section', 'activity')
            ->where('view', 'history')
        );
});

test('legacy bulk generate route remains available with view query compatibility', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $this->get(route('organization.documents.bulk'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/bulk/index')
            ->where('section', 'generate')
            ->where('view', 'roster')
        );

    $this->get(route('organization.documents.bulk', ['view' => 'signatures']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/bulk/index')
            ->where('section', 'requests')
            ->where('view', 'signatures')
        );

    $this->get(route('organization.documents.bulk', ['view' => 'history']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/bulk/index')
            ->where('section', 'activity')
            ->where('view', 'history')
        );
});

test('templates section is available to documents viewers without removing settings placement', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);

    $this->get(route('organization.documents.templates'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/templates')
            ->where('section', 'templates')
            ->has('system_templates')
            ->where('can.configure_placement', false)
            ->where('can.manage_document_types', false)
        );
});

test('templates section is forbidden without documents bulk or application settings access', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.documents.templates'))->assertForbidden();
});

test('documents.view only can open overview library and templates but not bulk sections', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);

    expect(DocumentsModuleAccess::visibleSections(['documents.view']))
        ->toHaveCount(3)
        ->and(DocumentsModuleAccess::defaultPath(['documents.view']))
        ->toBe('/organization/documents');

    $this->get(route('organization.documents'))->assertOk();
    $this->get(route('organization.documents.library'))->assertOk();
    $this->get(route('organization.documents.templates'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.configure_placement', false)
            ->where('can.manage_document_types', false)
        );

    $this->get(route('organization.documents.generate'))->assertForbidden();
    $this->get(route('organization.documents.requests'))->assertForbidden();
    $this->get(route('organization.documents.activity'))->assertForbidden();
    $this->get(route('organization.documents.bulk'))->assertForbidden();
});

test('bulk_documents.view only can open generate requests activity templates and legacy bulk', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    expect(DocumentsModuleAccess::visibleSections(['bulk_documents.view']))
        ->toHaveCount(4)
        ->and(DocumentsModuleAccess::defaultPath(['bulk_documents.view']))
        ->toBe('/organization/documents/generate');

    $this->get(route('organization.documents'))->assertForbidden();
    $this->get(route('organization.documents.library'))->assertForbidden();

    $this->get(route('organization.documents.generate'))->assertOk();
    $this->get(route('organization.documents.requests'))->assertOk();
    $this->get(route('organization.documents.activity'))->assertOk();
    $this->get(route('organization.documents.bulk'))->assertOk();
    $this->get(route('organization.documents.templates'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.configure_placement', false)
        );
});

test('bulk_documents.generate only cannot open generate or requests without view', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    setupBulkDocumentsCompany($user, ['bulk_documents.generate']);

    expect(DocumentsModuleAccess::canAccessModule(['bulk_documents.generate']))->toBeFalse()
        ->and(DocumentsModuleAccess::defaultPath(['bulk_documents.generate']))->toBeNull();

    $this->get(route('organization.documents.generate'))->assertForbidden();
    $this->get(route('organization.documents.requests'))->assertForbidden();
    $this->get(route('organization.documents.activity'))->assertForbidden();
    $this->get(route('organization.documents.bulk'))->assertForbidden();
    $this->get(route('organization.documents.templates'))->assertForbidden();
});

test('bulk_documents.signatures.review only cannot open requests without view', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    setupBulkDocumentsCompany($user, ['bulk_documents.signatures.review']);

    expect(DocumentsModuleAccess::canAccessModule(['bulk_documents.signatures.review']))->toBeFalse();

    $this->get(route('organization.documents.requests'))->assertForbidden();
    $this->get(route('organization.documents.bulk', ['view' => 'signatures']))->assertForbidden();
});

test('documents.view plus all bulk permissions can open every documents section', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $permissions = [
        'documents.view',
        'bulk_documents.view',
        'bulk_documents.generate',
        'bulk_documents.delete',
        'bulk_documents.email',
        'bulk_documents.signatures.review',
    ];

    setupBulkDocumentsCompany($user, $permissions);

    expect(DocumentsModuleAccess::visibleSections($permissions))->toHaveCount(6)
        ->and(DocumentsModuleAccess::defaultPath($permissions))->toBe('/organization/documents');

    $this->get(route('organization.documents'))->assertOk();
    $this->get(route('organization.documents.library'))->assertOk();
    $this->get(route('organization.documents.generate'))->assertOk();
    $this->get(route('organization.documents.requests'))->assertOk();
    $this->get(route('organization.documents.templates'))->assertOk();
    $this->get(route('organization.documents.activity'))->assertOk();
    $this->get(route('organization.documents.bulk'))->assertOk();
});

test('settings.application.view only can open templates with placement link flags', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['settings.application.view']);

    expect(DocumentsModuleAccess::visibleSections(['settings.application.view']))
        ->toHaveCount(1)
        ->and(DocumentsModuleAccess::defaultPath(['settings.application.view']))
        ->toBe('/organization/documents/templates');

    $this->get(route('organization.documents'))->assertForbidden();
    $this->get(route('organization.documents.generate'))->assertForbidden();

    $this->get(route('organization.documents.templates'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.configure_placement', true)
            ->where('can.update_placement', false)
            ->where('can.manage_document_types', false)
        );
});

test('settings.application.update does not open templates without view', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['settings.application.update']);

    expect(DocumentsModuleAccess::canAccessModule(['settings.application.update']))->toBeFalse();

    $this->get(route('organization.documents.templates'))->assertForbidden();
});

test('templates placement and document-types links follow their own permissions', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, [
        'documents.view',
        'settings.application.view',
        'settings.application.update',
        'settings.master-data.document-types.view',
    ]);

    $this->get(route('organization.documents.templates'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.configure_placement', true)
            ->where('can.update_placement', true)
            ->where('can.manage_document_types', true)
        );
});

test('users with no document permissions cannot open any documents module route', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);

    expect(DocumentsModuleAccess::canAccessModule(['employees.view']))->toBeFalse()
        ->and(DocumentsModuleAccess::defaultPath(['employees.view']))->toBeNull();

    $this->get(route('organization.documents'))->assertForbidden();
    $this->get(route('organization.documents.library'))->assertForbidden();
    $this->get(route('organization.documents.generate'))->assertForbidden();
    $this->get(route('organization.documents.requests'))->assertForbidden();
    $this->get(route('organization.documents.templates'))->assertForbidden();
    $this->get(route('organization.documents.activity'))->assertForbidden();
    $this->get(route('organization.documents.bulk'))->assertForbidden();
});

test('generate section payload stays scoped to the active company', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $companyA = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $this->get(route('organization.documents.generate'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/bulk/index')
            ->where('section', 'generate')
            ->where('company_name', $companyA->name)
        );

    $companyB = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

    $this->get(route('organization.documents.generate'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/bulk/index')
            ->where('section', 'generate')
            ->where('company_name', $companyB->name)
        );
});
