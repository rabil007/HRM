<?php

use App\Enums\DocumentAiMode;
use App\Models\DocumentAiSetting;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

test('platform viewer can access the centralized AI settings page with platform props', function () {
    storePlatformAiSettings([
        'enabled' => true,
        'provider' => 'openrouter',
        'openai_api_key' => 'sk-secret-openai-key',
        'openai_model' => 'gpt-test',
        'openrouter_api_key' => 'sk-secret-openrouter-key',
        'openrouter_model' => 'openrouter/test',
    ]);

    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, []);

    $response = $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('settings.ai.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ai')
            ->where('platform_ai.enabled', true)
            ->where('platform_ai.provider', 'openrouter')
            ->where('platform_ai.openai.has_api_key', true)
            ->where('platform_ai.openai.model', 'gpt-test')
            ->where('platform_ai.openrouter.has_api_key', true)
            ->where('platform_ai.openrouter.model', 'openrouter/test')
            ->where('document_ai.mode', 'off')
            ->where('document_ai.company_name', $company->name)
            ->where('can.platform_update', false)
            ->where('can.document_ai_manage', false)
            ->missing('platform_ai.openai.api_key')
            ->missing('platform_ai.openrouter.api_key')
            ->missing('smtp')
            ->missing('branding')
            ->missing('retention')
            ->missing('general')
            ->missing('whatsapp'),
        );

    expect($response->getContent())
        ->not->toContain('sk-secret-openai-key')
        ->not->toContain('sk-secret-openrouter-key');
});

test('company document ai manager without platform access receives only document ai props', function () {
    storePlatformAiSettings([
        'enabled' => true,
        'provider' => 'openai',
        'openai_api_key' => 'sk-platform-secret',
        'openai_model' => 'gpt-secret-model',
    ]);

    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    DocumentAiSetting::query()->create([
        'company_id' => $company->id,
        'mode' => DocumentAiMode::Optional,
        'updated_by' => $user->id,
    ]);

    grantCompanyPermissions($user, $company, ['documents.ai.manage']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('settings.ai.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/ai')
            ->where('platform_ai', null)
            ->where('document_ai.mode', 'optional')
            ->where('document_ai.provider_available', true)
            ->where('document_ai.company_name', $company->name)
            ->where('can.platform_update', false)
            ->where('can.document_ai_manage', true)
            ->missing('smtp')
            ->missing('branding')
            ->missing('retention')
            ->missing('general')
            ->missing('whatsapp')
            ->missing('platform_ai.enabled')
            ->missing('platform_ai.provider')
            ->missing('platform_ai.openai')
            ->missing('platform_ai.openrouter'),
        );
});

test('unauthorized user cannot access the centralized AI settings page', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.security.view',
        'documents.ai.use',
    ]);

    $this->actingAs($user)
        ->get(route('settings.ai.edit'))
        ->assertForbidden();
});

test('legacy application ai tab redirects to the centralized AI settings page', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');
    setupCompanyWithApplicationSettingsPermissions($user, []);

    $this->actingAs($user)
        ->get('/settings/application?tab=ai')
        ->assertRedirect(route('settings.ai.edit'));
});

test('settings hub is accessible with documents ai manage alone', function () {
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, ['documents.ai.manage']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('settings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/index'));
});

test('document ai mode on the AI page follows the active company', function () {
    $user = User::factory()->create();
    ['company' => $companyA] = makeDocumentFixtures();
    ['company' => $companyB] = makeDocumentFixtures();

    DocumentAiSetting::query()->create([
        'company_id' => $companyA->id,
        'mode' => DocumentAiMode::Automatic,
        'updated_by' => $user->id,
    ]);
    DocumentAiSetting::query()->create([
        'company_id' => $companyB->id,
        'mode' => DocumentAiMode::Optional,
        'updated_by' => $user->id,
    ]);

    grantCompanyPermissions($user, $companyA, ['documents.ai.manage'], 'company-a-ai-manager');
    grantCompanyPermissions($user, $companyB, ['documents.ai.manage'], 'company-b-ai-manager');

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->get(route('settings.ai.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('document_ai.mode', 'automatic')
            ->where('document_ai.company_name', $companyA->name),
        );

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyB->id])
        ->get(route('settings.ai.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('document_ai.mode', 'optional')
            ->where('document_ai.company_name', $companyB->name),
        );
});
