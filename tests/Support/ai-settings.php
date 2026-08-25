<?php

use App\Models\User;
use App\Services\Settings\AiSettingsService;

/**
 * @param  array{
 *     enabled?: bool,
 *     provider?: string,
 *     openai_api_key?: string|null,
 *     openai_model?: string|null,
 *     openrouter_api_key?: string|null,
 *     openrouter_model?: string|null
 * }  $overrides
 */
function storePlatformAiSettings(array $overrides = [], ?User $actor = null): void
{
    $actor ??= User::factory()->create();

    app(AiSettingsService::class)->storeFromPayload(array_merge([
        'enabled' => true,
        'provider' => AiSettingsService::PROVIDER_OPENAI,
        'openai_api_key' => null,
        'openai_model' => '',
        'openrouter_api_key' => null,
        'openrouter_model' => '',
    ], $overrides), $actor);
}

/**
 * @return array<string, mixed>
 */
function fakeSmartSearchIntent(array $overrides = []): array
{
    return array_merge([
        'status' => null,
        'department' => null,
        'position' => null,
        'nationality' => null,
        'rank' => null,
        'crew_status' => null,
        'unsupported_terms' => [],
    ], $overrides);
}

/**
 * @param  array{
 *     enabled?: bool,
 *     provider?: string,
 *     openai_model?: string|null,
 *     openrouter_api_key?: string|null,
 *     openrouter_model?: string|null
 * }  $overrides
 */
function enableEmployeeSmartSearch(?string $openaiKey = 'test-openai-key', array $overrides = []): void
{
    storePlatformAiSettings(array_merge([
        'enabled' => true,
        'provider' => AiSettingsService::PROVIDER_OPENAI,
        'openai_api_key' => ($openaiKey !== null && $openaiKey !== '') ? $openaiKey : null,
    ], $overrides));
}

function interpretSmartSearch(User $user, int $companyId, string $prompt, array $extra = [])
{
    return test()->actingAs($user)
        ->withSession(['current_company_id' => $companyId])
        ->postJson(route('organization.employees.smart-search.interpret'), array_merge([
            'prompt' => $prompt,
        ], $extra));
}
