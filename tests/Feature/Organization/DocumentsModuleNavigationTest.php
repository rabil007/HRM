<?php

use App\Models\User;
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

    $company = setupBulkDocumentsCompany($user, ['bulk_documents.view']);

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

    expect($company->id)->toBeInt();
});

test('legacy bulk generate route remains available', function () {
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
            ->has('document_types')
            ->where('can.configure_placement', false)
        );
});

test('templates section is forbidden without documents bulk or application settings access', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['employees.view']);

    $this->get(route('organization.documents.templates'))->assertForbidden();
});
