<?php

use App\Models\AppSetting;
use App\Models\User;
use App\Services\AiProviderConnectionTester;
use App\Services\EmployeeSmartSearchInterpreter;
use App\Support\Settings\SettingKey;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Prompts\AgentPrompt;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

test('platform viewer can see AI settings but never decrypted keys', function () {
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
    setupCompanyWithApplicationSettingsPermissions($user, []);

    $response = $this->actingAs($user)
        ->get(route('application.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/application')
            ->where('ai.enabled', true)
            ->where('ai.provider', 'openrouter')
            ->where('ai.openai.has_api_key', true)
            ->where('ai.openai.model', 'gpt-test')
            ->where('ai.openrouter.has_api_key', true)
            ->where('ai.openrouter.model', 'openrouter/test')
            ->missing('ai.openai.api_key')
            ->missing('ai.openrouter.api_key')
            ->missing('openai_api_key')
            ->missing('openrouter_api_key'),
        );

    expect($response->getContent())
        ->not->toContain('sk-secret-openai-key')
        ->not->toContain('sk-secret-openrouter-key');
});

test('unauthorized user cannot view platform AI settings', function () {
    $user = User::factory()->create();
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.application.view',
        'settings.application.update',
    ]);

    $this->actingAs($user)
        ->get(route('application.edit'))
        ->assertForbidden();
});

test('platform manager can update AI settings', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    setupCompanyWithApplicationSettingsPermissions($user, []);

    $this->actingAs($user)
        ->put(route('application.ai.update'), [
            'enabled' => true,
            'provider' => 'openai',
            'openai_api_key' => 'sk-live-openai',
            'openai_model' => 'gpt-test',
            'openrouter_api_key' => 'sk-live-openrouter',
            'openrouter_model' => 'openrouter/test',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    Cache::forget('app.settings.all');

    expect(setting(SettingKey::AiSmartSearchEnabled))->toBe('1')
        ->and(setting(SettingKey::AiProvider))->toBe('openai')
        ->and(setting(SettingKey::AiOpenAiModel))->toBe('gpt-test')
        ->and(setting(SettingKey::AiOpenRouterModel))->toBe('openrouter/test')
        ->and(Crypt::decryptString((string) setting(SettingKey::AiOpenAiApiKey)))->toBe('sk-live-openai')
        ->and(Crypt::decryptString((string) setting(SettingKey::AiOpenRouterApiKey)))->toBe('sk-live-openrouter');
});

test('AI settings update requires privileged 2FA when enforcement is on', function () {
    enablePrivilegedTwoFactorEnforcement();

    storePlatformAiSettings([
        'openai_api_key' => 'keep-openai',
        'openrouter_api_key' => 'keep-openrouter',
    ]);

    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    setupCompanyWithApplicationSettingsPermissions($user, [
        'settings.security.view',
    ]);

    $this->actingAs($user)
        ->put(route('application.ai.update'), [
            'enabled' => true,
            'provider' => 'openrouter',
            'openai_api_key' => 'attacker-openai',
            'openrouter_api_key' => 'attacker-openrouter',
        ])
        ->assertRedirect(route('security.edit'));

    Cache::forget('app.settings.all');

    expect(Crypt::decryptString((string) setting(SettingKey::AiOpenAiApiKey)))->toBe('keep-openai')
        ->and(Crypt::decryptString((string) setting(SettingKey::AiOpenRouterApiKey)))->toBe('keep-openrouter');
});

test('OpenAI and OpenRouter keys are encrypted at rest', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    setupCompanyWithApplicationSettingsPermissions($user, []);

    $this->actingAs($user)
        ->put(route('application.ai.update'), [
            'enabled' => false,
            'provider' => 'openai',
            'openai_api_key' => 'sk-encrypted-openai',
            'openrouter_api_key' => 'sk-encrypted-openrouter',
        ])
        ->assertRedirect();

    $openaiStored = AppSetting::query()->where('key', SettingKey::AiOpenAiApiKey)->value('value');
    $openrouterStored = AppSetting::query()->where('key', SettingKey::AiOpenRouterApiKey)->value('value');

    expect($openaiStored)->not->toBe('sk-encrypted-openai')
        ->and($openrouterStored)->not->toBe('sk-encrypted-openrouter')
        ->and(Crypt::decryptString((string) $openaiStored))->toBe('sk-encrypted-openai')
        ->and(Crypt::decryptString((string) $openrouterStored))->toBe('sk-encrypted-openrouter');
});

test('blank API keys preserve stored secrets', function () {
    storePlatformAiSettings([
        'openai_api_key' => 'keep-openai',
        'openrouter_api_key' => 'keep-openrouter',
        'openai_model' => 'old-openai',
        'openrouter_model' => 'old-openrouter',
    ]);

    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    setupCompanyWithApplicationSettingsPermissions($user, []);

    $this->actingAs($user)
        ->put(route('application.ai.update'), [
            'enabled' => true,
            'provider' => 'openrouter',
            'openai_api_key' => '',
            'openrouter_api_key' => '',
            'openai_model' => 'new-openai',
            'openrouter_model' => 'new-openrouter',
        ])
        ->assertRedirect();

    Cache::forget('app.settings.all');

    expect(Crypt::decryptString((string) setting(SettingKey::AiOpenAiApiKey)))->toBe('keep-openai')
        ->and(Crypt::decryptString((string) setting(SettingKey::AiOpenRouterApiKey)))->toBe('keep-openrouter')
        ->and(setting(SettingKey::AiOpenAiModel))->toBe('new-openai')
        ->and(setting(SettingKey::AiOpenRouterModel))->toBe('new-openrouter')
        ->and(setting(SettingKey::AiProvider))->toBe('openrouter');
});

test('invalid provider and client company_id are rejected', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    setupCompanyWithApplicationSettingsPermissions($user, []);

    $this->actingAs($user)
        ->putJson(route('application.ai.update'), [
            'enabled' => true,
            'provider' => 'anthropic',
        ])
        ->assertUnprocessable();

    $this->actingAs($user)
        ->putJson(route('application.ai.update'), [
            'enabled' => true,
            'provider' => 'openai',
            'company_id' => 99,
        ])
        ->assertUnprocessable();
});

test('changing providers does not erase the other provider credentials', function () {
    storePlatformAiSettings([
        'provider' => 'openai',
        'openai_api_key' => 'openai-keep',
        'openrouter_api_key' => 'openrouter-keep',
    ]);

    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    setupCompanyWithApplicationSettingsPermissions($user, []);

    $this->actingAs($user)
        ->put(route('application.ai.update'), [
            'enabled' => true,
            'provider' => 'openrouter',
        ])
        ->assertRedirect();

    Cache::forget('app.settings.all');

    expect(setting(SettingKey::AiProvider))->toBe('openrouter')
        ->and(Crypt::decryptString((string) setting(SettingKey::AiOpenAiApiKey)))->toBe('openai-keep')
        ->and(Crypt::decryptString((string) setting(SettingKey::AiOpenRouterApiKey)))->toBe('openrouter-keep');
});

test('selected OpenAI provider uses the stored OpenAI key and OpenRouter key is ignored', function () {
    storePlatformAiSettings([
        'enabled' => true,
        'provider' => 'openai',
        'openai_api_key' => 'stored-openai-key',
        'openrouter_api_key' => 'stored-openrouter-key',
    ]);

    ['user' => $user, 'companyA' => $company] = makeCompanyAuthorizationPair();
    grantCompanyPermissions($user, $company, ['employees.view']);

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent(['status' => 'active']),
    ]);

    interpretSmartSearch($user, $company->id, 'active crew')->assertOk();

    EmployeeSmartSearchInterpreter::assertPrompted(function (AgentPrompt $prompt): bool {
        return $prompt->provider->name() === 'openai'
            && $prompt->prompt === 'active crew';
    });

    expect(config('ai.providers.openai.key'))->toBe('stored-openai-key');
});

test('selected OpenRouter provider uses the stored OpenRouter key', function () {
    storePlatformAiSettings([
        'enabled' => true,
        'provider' => 'openrouter',
        'openai_api_key' => 'stored-openai-key',
        'openrouter_api_key' => 'stored-openrouter-key',
        'openrouter_model' => 'openrouter/test-model',
    ]);

    ['user' => $user, 'companyA' => $company] = makeCompanyAuthorizationPair();
    grantCompanyPermissions($user, $company, ['employees.view']);

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent(['status' => 'active']),
    ]);

    interpretSmartSearch($user, $company->id, 'active crew')->assertOk();

    EmployeeSmartSearchInterpreter::assertPrompted(function (AgentPrompt $prompt): bool {
        return $prompt->provider->name() === 'openrouter'
            && $prompt->model === 'openrouter/test-model';
    });

    expect(config('ai.providers.openrouter.key'))->toBe('stored-openrouter-key');
});

test('missing selected-provider key fails safely without invoking AI', function () {
    storePlatformAiSettings([
        'enabled' => true,
        'provider' => 'openai',
        'openrouter_api_key' => 'only-openrouter',
    ]);

    ['user' => $user, 'companyA' => $company] = makeCompanyAuthorizationPair();
    grantCompanyPermissions($user, $company, ['employees.view']);

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent(['status' => 'active']),
    ]);

    interpretSmartSearch($user, $company->id, 'active crew')
        ->assertStatus(503)
        ->assertJsonPath('message', 'Employee smart search is temporarily unavailable.');

    EmployeeSmartSearchInterpreter::assertNeverPrompted();
});

test('disabled Smart Employee Search prevents the AI call even when credentials exist', function () {
    storePlatformAiSettings([
        'enabled' => false,
        'openai_api_key' => 'stored-openai-key',
    ]);

    config(['employee-smart-search.enabled' => true]);

    ['user' => $user, 'companyA' => $company] = makeCompanyAuthorizationPair();
    grantCompanyPermissions($user, $company, ['employees.view']);

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent(['status' => 'active']),
    ]);

    interpretSmartSearch($user, $company->id, 'active crew')
        ->assertForbidden()
        ->assertJsonPath('message', 'Employee smart search is not enabled.');

    EmployeeSmartSearchInterpreter::assertNeverPrompted();
});

test('enabling Smart Employee Search takes effect without an env change', function () {
    config([
        'employee-smart-search.enabled' => false,
        'ai.providers.openai.key' => '',
    ]);

    storePlatformAiSettings([
        'enabled' => true,
        'openai_api_key' => 'stored-openai-key',
    ]);

    ['user' => $user, 'companyA' => $company] = makeCompanyAuthorizationPair();
    grantCompanyPermissions($user, $company, ['employees.view']);

    EmployeeSmartSearchInterpreter::fake([
        fakeSmartSearchIntent(['status' => 'active']),
    ]);

    interpretSmartSearch($user, $company->id, 'active crew')
        ->assertOk()
        ->assertJsonPath('filters.status', 'active');
});

test('test connection uses only the selected stored provider and is faked', function () {
    storePlatformAiSettings([
        'provider' => 'openrouter',
        'openai_api_key' => 'stored-openai-key',
        'openrouter_api_key' => 'stored-openrouter-key',
    ]);

    AiProviderConnectionTester::fake([
        ['status' => 'OK'],
    ]);

    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    setupCompanyWithApplicationSettingsPermissions($user, []);

    $this->actingAs($user)
        ->postJson(route('application.ai.test'), [
            'provider' => 'openai',
            'openai_api_key' => 'request-openai-key',
        ])
        ->assertUnprocessable();

    AiProviderConnectionTester::assertNeverPrompted();

    $this->actingAs($user)
        ->postJson(route('application.ai.test'))
        ->assertOk()
        ->assertJsonPath('message', 'OpenRouter connection successful.')
        ->assertJsonMissingPath('api_key');

    AiProviderConnectionTester::assertPrompted(function (AgentPrompt $prompt): bool {
        $instructions = (string) $prompt->agent->instructions();

        return $prompt->provider->name() === 'openrouter'
            && $prompt->prompt === 'Reply with OK.'
            && str_contains($instructions, 'untrusted')
            && ! str_contains($instructions, 'stored-openrouter-key');
    });
});

test('test connection returns a generic failure and never leaks secrets', function () {
    storePlatformAiSettings([
        'openai_api_key' => 'stored-openai-key',
    ]);

    AiProviderConnectionTester::fake(function (): array {
        throw new RuntimeException('provider timeout with key sk-secret');
    });

    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    setupCompanyWithApplicationSettingsPermissions($user, []);

    $response = $this->actingAs($user)
        ->postJson(route('application.ai.test'))
        ->assertUnprocessable();

    $payload = json_encode($response->json());

    expect($payload)->not->toContain('sk-secret')
        ->not->toContain('provider timeout')
        ->not->toContain('stored-openai-key');
});

test('test connection reports a rejected API key without leaking provider payload', function () {
    storePlatformAiSettings([
        'provider' => 'openrouter',
        'openrouter_api_key' => 'or-test-rejected-key',
    ]);

    Http::fake([
        'openrouter.ai/*' => Http::response([
            'error' => [
                'message' => 'User not found.',
                'code' => 401,
            ],
        ], 401),
    ]);

    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    setupCompanyWithApplicationSettingsPermissions($user, []);

    $response = $this->actingAs($user)
        ->postJson(route('application.ai.test'))
        ->assertUnprocessable()
        ->assertJsonPath(
            'errors.provider.0',
            'The stored API key was rejected by the selected provider.',
        );

    $payload = json_encode($response->json());

    expect($payload)->not->toContain('User not found')
        ->not->toContain('or-test-rejected-key');
});

test('API keys never appear in AI settings activity properties', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    $company = setupCompanyWithSettingsPermissions($user, []);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put(route('application.ai.update'), [
            'enabled' => true,
            'provider' => 'openai',
            'openai_api_key' => 'sk-activity-openai',
            'openrouter_api_key' => 'sk-activity-openrouter',
            'openai_model' => 'gpt-test',
            'openrouter_model' => 'openrouter/test',
        ])
        ->assertRedirect();

    $activity = Activity::query()
        ->where('log_name', 'platform')
        ->where('description', 'updated platform AI settings')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and((int) $activity->causer_id)->toBe($user->id)
        ->and($activity->properties->get('scope'))->toBe('platform')
        ->and($activity->properties->get('enabled'))->toBeTrue()
        ->and($activity->properties->get('provider'))->toBe('openai')
        ->and($activity->properties->get('openai_credential_replaced'))->toBeTrue()
        ->and($activity->properties->get('openrouter_credential_replaced'))->toBeTrue()
        ->and($activity->properties->get('enabled_changed'))->toBeTrue()
        ->and($activity->properties->get('provider_changed'))->toBeFalse();

    $serialized = $activity->properties->toJson();

    expect($serialized)
        ->not->toContain('sk-activity-openai')
        ->not->toContain('sk-activity-openrouter');
});
