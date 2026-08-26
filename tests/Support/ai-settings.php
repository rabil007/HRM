<?php

use App\Models\User;
use App\Services\Settings\AiSettingsService;
use Illuminate\Support\Facades\Http;

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
    $unsupported = $overrides['unsupported_terms'] ?? [];
    $ambiguous = $overrides['ambiguous_terms'] ?? [];
    $criteria = $overrides['criteria'] ?? null;

    unset($overrides['unsupported_terms'], $overrides['ambiguous_terms'], $overrides['criteria']);

    if (! is_array($criteria)) {
        $criteria = [];

        foreach ($overrides as $concept => $value) {
            if (in_array($concept, [
                'company_id',
                'department_id',
                'position_id',
                'employees',
                'sql',
                'filters',
                'emirates_id',
                'passport_number',
            ], true)) {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            if ($concept === 'emirates_id_presence') {
                $criteria[] = [
                    'concept' => 'emirates_id',
                    'operator' => (string) $value,
                    'value' => null,
                ];

                continue;
            }

            $criteria[] = [
                'concept' => (string) $concept,
                'operator' => 'equals',
                'value' => is_string($value) ? $value : null,
            ];
        }
    }

    return [
        'criteria' => $criteria,
        'ambiguous_terms' => $ambiguous,
        'unsupported_terms' => $unsupported,
    ];
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

function fakeOpenRouterSmartSearchContent(string $content, int $status = 200): void
{
    Http::fake([
        'openrouter.ai/*' => Http::response([
            'id' => 'gen-test',
            'model' => 'anthropic/claude-sonnet-4.6',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $content,
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 10,
                'completion_tokens' => 10,
            ],
        ], $status),
    ]);
}
