<?php

use App\Models\DocumentRequirement;
use App\Models\DocumentType;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

test('authorized users can view a document type detail page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, [
        'settings.master-data.document-types.view',
    ]);

    $this->get(route('organization.documents.configuration.show', $passportType))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/configuration/document-type-show')
            ->where('document_type.id', $passportType->id)
            ->where('document_type.title', $passportType->title)
            ->where('document_type.is_active', true)
            ->where('document_type.status_label', 'Active')
            ->where('document_type.requirement.is_required', false)
            ->where('document_type.requirement.scope_kind', 'optional')
            ->where('document_type.requirement.scope_summary', 'Optional document')
            ->where('document_type.compliance_links', [])
            ->where('can.update', false)
            ->where('can.delete', false)
            ->where('can_view_audit', false)
            ->where('recent_activity', []));
});

test('unauthorized users cannot view document type detail', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.view']);

    $this->get(route('organization.documents.configuration.show', $passportType))
        ->assertForbidden();
});

test('optional document type detail exposes tracked details and inactive state', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();
    $passportType->update(['is_active' => false]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.document-types.view',
    ]);

    $this->get(route('organization.documents.configuration.show', $passportType))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('document_type.is_active', false)
            ->where('document_type.status_label', 'Inactive')
            ->where('document_type.requirement.requirement_label', 'Optional')
            ->where('document_type.requirement.tracked_details', [])
            ->where('document_type.requirement.who_needs_copy', 'This document is optional and is not enforced for employee compliance.'));
});

test('required for all document type detail includes compliance links when documents view is granted', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();
    $requirement = makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);
    $requirement->update([
        'require_issue_date' => true,
        'require_expiry_date' => true,
        'require_document_number' => true,
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.document-types.view',
        'settings.master-data.document-types.update',
        'documents.view',
    ]);

    $this->get(route('organization.documents.configuration.show', $passportType))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('document_type.requirement.is_required', true)
            ->where('document_type.requirement.scope_kind', 'all_employees')
            ->where('document_type.requirement.scope_summary', 'Required for all employees')
            ->where('document_type.requirement.applies_to_label', 'All employees')
            ->where('document_type.requirement.matching_rule_applies', false)
            ->where('document_type.requirement.tracked_details.0.label', 'Document number')
            ->where('document_type.requirement.tracked_details.1.label', 'Issue date')
            ->where('document_type.requirement.tracked_details.2.label', 'Expiry date')
            ->where('can.update', true)
            ->has('document_type.compliance_links', 2)
            ->where('document_type.compliance_links.0.label', 'View missing employees')
            ->where('document_type.compliance_links.1.label', 'View documents'));
});

test('selected groups document type detail shows company targets and matching rule', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();
    $scopes = makeDocumentRequirementMatchScopes($company->id);
    makeDocumentRequirement(
        $company->id,
        $passportType->id,
        departmentIds: [$scopes['crew']->id],
        rankIds: [$scopes['captain']->id, $scopes['chiefEngineer']->id],
    );

    grantCompanyPermissions($user, $company, [
        'settings.master-data.document-types.view',
    ]);

    $this->get(route('organization.documents.configuration.show', $passportType))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('document_type.requirement.scope_kind', 'selected_groups')
            ->where('document_type.requirement.scope_summary', 'Required for selected groups')
            ->where('document_type.requirement.matching_rule_applies', true)
            ->where('document_type.requirement.targets.departments.0.name', 'Crew')
            ->has('document_type.requirement.targets.ranks', 2)
            ->where('document_type.requirement.targets.positions', [])
            ->where('document_type.requirement.targets.projects', [])
            ->where('document_type.compliance_links', []));
});

test('document type detail isolates requirement targets by company', function () {
    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    $this->actingAs($user);

    $passportType = DocumentType::query()->firstOrCreate(
        ['title' => 'Passport Copy'],
        ['is_active' => true],
    );

    $scopesA = makeDocumentRequirementMatchScopes($companyA->id);
    makeDocumentRequirement(
        $companyA->id,
        $passportType->id,
        departmentIds: [$scopesA['crew']->id],
    );

    grantCompanyPermissions($user, $companyA, [
        'settings.master-data.document-types.view',
    ]);
    grantCompanyPermissions($user, $companyB, [
        'settings.master-data.document-types.view',
    ]);

    session(['current_company_id' => $companyB->id]);

    $this->get(route('organization.documents.configuration.show', $passportType))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('document_type.requirement.is_required', false)
            ->where('document_type.requirement.scope_kind', 'optional')
            ->where('document_type.requirement.targets.departments', []));

    expect(DocumentRequirement::query()
        ->where('company_id', $companyB->id)
        ->where('document_type_id', $passportType->id)
        ->exists())->toBeFalse();
});

test('document type detail shows recent activity only with audit view', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();
    $requirement = makeDocumentRequirement($company->id, $passportType->id, requiredForAll: true);

    Activity::query()->create([
        'log_name' => 'default',
        'description' => 'Passport Copy: Optional → Required for all employees',
        'subject_type' => DocumentRequirement::class,
        'subject_id' => $requirement->id,
        'event' => 'updated',
        'company_id' => $company->id,
        'properties' => [
            'company_id' => $company->id,
            'old' => 'Optional',
            'attributes' => 'Required for all employees',
        ],
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.document-types.view',
    ]);

    $this->get(route('organization.documents.configuration.show', $passportType))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_view_audit', false)
            ->where('recent_activity', []));

    grantCompanyPermissions($user, $company, [
        'settings.master-data.document-types.view',
        'audit.view',
    ]);

    $this->get(route('organization.documents.configuration.show', $passportType))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can_view_audit', true)
            ->has('recent_activity', 1)
            ->where('recent_activity.0.description', 'Passport Copy: Optional → Required for all employees'));
});

test('updating a document type from detail redirects back to the detail page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, [
        'settings.master-data.document-types.view',
        'settings.master-data.document-types.update',
    ]);

    $this->put(route('settings.master-data.document-types.update', $passportType), [
        'title' => $passportType->title,
        'is_active' => true,
        'is_required' => true,
        'required_for_all' => true,
        'redirect_to' => 'show',
    ])->assertRedirect(route('organization.documents.configuration.show', $passportType));
});

test('delete permission flag is exposed on document type detail', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    ['company' => $company, 'passportType' => $passportType] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, [
        'settings.master-data.document-types.view',
        'settings.master-data.document-types.delete',
    ]);

    $this->get(route('organization.documents.configuration.show', $passportType))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.delete', true)
            ->where('can.update', false));
});
