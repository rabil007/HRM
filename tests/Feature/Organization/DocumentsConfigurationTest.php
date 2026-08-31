<?php

use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

test('authorized users can open document types from documents configuration', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, [
        'settings.master-data.document-types.view',
    ]);

    $this->get(route('organization.documents.configuration'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/configuration/document-types')
            ->has('document_types')
            ->where('open_document_type', null)
            ->where('document_types', function ($types) use ($passportType) {
                return collect($types)->contains('id', $passportType->id);
            }));
});

test('documents view does not grant document type configuration access', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);

    $this->get(route('organization.documents'))->assertOk();
    $this->get(route('organization.documents.configuration'))->assertForbidden();
    $this->get('/settings/master-data/document-types')->assertForbidden();
});

test('the settings document types bookmark redirects to documents configuration', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, [
        'settings.master-data.document-types.view',
    ]);

    $this->get('/settings/master-data/document-types?edit='.$passportType->id.'&search=pass')
        ->assertRedirect(route('organization.documents.configuration', [
            'edit' => $passportType->id,
            'search' => 'pass',
        ]));
});

test('document type edit query opens that type for configuration', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, [
        'settings.master-data.document-types.view',
        'settings.master-data.document-types.update',
    ]);

    $this->get(route('organization.documents.configuration', ['edit' => $passportType->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/configuration/document-types')
            ->where('open_document_type.id', $passportType->id)
            ->where('open_document_type.title', $passportType->title));
});

test('document type configuration keeps company requirement isolation', function () {
    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    $this->actingAs($user);

    $passportType = DocumentType::query()->firstOrCreate(
        ['title' => 'Passport Copy'],
        ['is_active' => true],
    );

    grantCompanyPermissions($user, $companyA, [
        'settings.master-data.document-types.view',
        'settings.master-data.document-types.update',
    ]);
    grantCompanyPermissions($user, $companyB, [
        'settings.master-data.document-types.view',
        'settings.master-data.document-types.update',
    ]);

    makeDocumentRequirement($companyA->id, $passportType->id, requiredForAll: true);

    session(['current_company_id' => $companyB->id]);

    $this->get(route('organization.documents.configuration'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('document_types', function ($types) use ($passportType) {
                $match = collect($types)->firstWhere('id', $passportType->id);

                return $match !== null
                    && ($match['requirement']['is_required'] ?? true) === false;
            }));

    expect(DocumentRequirement::query()
        ->where('company_id', $companyB->id)
        ->where('document_type_id', $passportType->id)
        ->exists())->toBeFalse();
});
