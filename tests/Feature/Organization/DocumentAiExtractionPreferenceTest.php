<?php

use App\Models\Permission;
use App\Models\User;
use App\Support\Authorization\ApplicationPermissionRegistry;
use App\Support\EmployeeDocuments\UpdateDocumentAiExtractionPreference;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

test('guests cannot update document ai extraction preference', function () {
    $this->put(route('preferences.document-ai-extraction.update'), [
        'enabled' => true,
    ])->assertRedirect();
});

test('authenticated users can persist document ai extraction preference on their account', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.upload']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->from('/dashboard')
        ->put(route('preferences.document-ai-extraction.update'), [
            'enabled' => true,
        ])
        ->assertRedirect('/dashboard');

    expect($user->fresh()->document_ai_extraction_preference)->toBe(
        UpdateDocumentAiExtractionPreference::VALUE_ON,
    );

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->from('/dashboard')
        ->put(route('preferences.document-ai-extraction.update'), [
            'enabled' => false,
        ])
        ->assertRedirect('/dashboard');

    expect($user->fresh()->document_ai_extraction_preference)->toBe(
        UpdateDocumentAiExtractionPreference::VALUE_OFF,
    );
});

test('document ai extraction preference is shared on inertia requests', function () {
    $user = User::factory()->create();
    $user->forceFill([
        'document_ai_extraction_preference' => UpdateDocumentAiExtractionPreference::VALUE_OFF,
    ])->save();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.upload', 'employees.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('document_ai_extraction_preference', 'off'));
});

test('users cannot set another users document ai extraction preference', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($owner, $company, ['documents.upload']);
    grantCompanyPermissions($stranger, $company, ['documents.upload']);

    $this->actingAs($stranger)
        ->withSession(['current_company_id' => $company->id])
        ->from('/dashboard')
        ->put(route('preferences.document-ai-extraction.update'), [
            'enabled' => true,
            'user_id' => $owner->id,
        ])
        ->assertRedirect('/dashboard')
        ->assertSessionHasErrors('user_id');

    expect($owner->fresh()->document_ai_extraction_preference)->toBeNull()
        ->and($stranger->fresh()->document_ai_extraction_preference)->toBeNull();
});

test('invalid document ai extraction preference payloads are rejected', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.upload']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->from('/dashboard')
        ->put(route('preferences.document-ai-extraction.update'), [])
        ->assertRedirect('/dashboard')
        ->assertSessionHasErrors('enabled');

    expect($user->fresh()->document_ai_extraction_preference)->toBeNull();
});

test('documents.ai.use remains absent from the registry after sync', function () {
    ApplicationPermissionRegistry::sync();

    expect(ApplicationPermissionRegistry::names())->not->toContain('documents.ai.use')
        ->and(
            Permission::query()
                ->where('name', 'documents.ai.use')
                ->where('guard_name', 'web')
                ->exists(),
        )->toBeFalse();
});

test('remove documents.ai.use migration down does not recreate the permission', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    Permission::query()->firstOrCreate([
        'name' => 'documents.ai.use',
        'guard_name' => 'web',
    ]);

    $migration = require database_path('migrations/2026_10_17_172200_remove_documents_ai_use_permission.php');
    $migration->up();

    expect(
        Permission::query()
            ->where('name', 'documents.ai.use')
            ->where('guard_name', 'web')
            ->exists(),
    )->toBeFalse();

    $migration->down();

    expect(
        Permission::query()
            ->where('name', 'documents.ai.use')
            ->where('guard_name', 'web')
            ->exists(),
    )->toBeFalse();

    // Sync must not resurrect the retired permission either.
    ApplicationPermissionRegistry::sync();

    expect(ApplicationPermissionRegistry::names())->not->toContain('documents.ai.use');
});
