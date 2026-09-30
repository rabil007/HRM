<?php

use App\Enums\DocumentAiMode;
use App\Models\DocumentAiSetting;
use App\Models\User;
use App\Support\Authorization\ApplicationPermissionRegistry;
use App\Support\EmployeeDocuments\DocumentAiSettings;
use Database\Seeders\PermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

test('document ai defaults to off and the AI settings page exposes safe availability props', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, [
        'documents.ai.manage',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('settings.ai.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ai')
            ->where('document_ai.mode', 'off')
            ->has('document_ai.provider_available')
            ->where('document_ai.available', false)
            ->where('platform_ai', null)
        );

    expect(DocumentAiSetting::query()->where('company_id', $company->id)->exists())->toBeFalse();
});

test('document types configuration no longer loads document ai settings', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, [
        'settings.master-data.document-types.view',
        'documents.ai.manage',
    ]);

    $this->actingAs($user)
        ->get(route('organization.documents.configuration'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/documents/configuration/document-types')
            ->missing('document_ai_settings'),
        );
});

test('authorized users can update the company document ai mode', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, [
        'settings.master-data.document-types.view',
        'documents.ai.manage',
    ]);

    $this->actingAs($user)
        ->put(route('organization.documents.ai-settings.update'), [
            'mode' => 'optional',
        ])
        ->assertRedirect();

    $setting = DocumentAiSetting::query()
        ->where('company_id', $company->id)
        ->first();

    expect($setting)->not->toBeNull()
        ->and($setting?->mode)->toBe(DocumentAiMode::Optional)
        ->and($setting?->updated_by)->toBe($user->id)
        ->and(app(DocumentAiSettings::class)->modeForCompany($company->id))->toBe(DocumentAiMode::Optional);

    $activity = Activity::query()
        ->where('subject_type', DocumentAiSetting::class)
        ->where('subject_id', $setting?->id)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity?->company_id)->toBe($company->id);
});

test('documents ai use permission does not allow changing company ai settings', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, [
        'documents.ai.use',
    ]);

    $this->actingAs($user)
        ->put(route('organization.documents.ai-settings.update'), [
            'mode' => 'automatic',
        ])
        ->assertForbidden();

    expect(DocumentAiSetting::query()->where('company_id', $company->id)->exists())->toBeFalse();
});

test('document ai mode validation rejects unsupported values and submitted company ownership', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();

    grantCompanyPermissions($user, $company, [
        'documents.ai.manage',
    ]);

    $this->actingAs($user)
        ->put(route('organization.documents.ai-settings.update'), [
            'mode' => 'always-on',
        ])
        ->assertSessionHasErrors('mode');

    $this->actingAs($user)
        ->put(route('organization.documents.ai-settings.update'), [
            'mode' => 'optional',
            'company_id' => $company->id,
        ])
        ->assertSessionHasErrors('company_id');

    expect(DocumentAiSetting::query()->where('company_id', $company->id)->exists())->toBeFalse();
});

test('document ai settings remain isolated to the active company', function () {
    $user = User::factory()->create();
    ['company' => $companyA] = makeDocumentFixtures();
    ['company' => $companyB] = makeDocumentFixtures();

    grantCompanyPermissions($user, $companyA, ['documents.ai.manage'], 'company-a-ai-manager');
    grantCompanyPermissions($user, $companyB, ['documents.ai.manage'], 'company-b-ai-manager');

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->put(route('organization.documents.ai-settings.update'), [
            'mode' => 'automatic',
        ])
        ->assertRedirect();

    expect(app(DocumentAiSettings::class)->modeForCompany($companyA->id))->toBe(DocumentAiMode::Automatic)
        ->and(app(DocumentAiSettings::class)->modeForCompany($companyB->id))->toBe(DocumentAiMode::Off);
});

test('document ai permissions are part of the application permission registry', function () {
    $names = ApplicationPermissionRegistry::names();

    expect($names)->toContain('documents.ai.use', 'documents.ai.manage');
});
