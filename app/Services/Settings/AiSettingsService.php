<?php

namespace App\Services\Settings;

use App\Exceptions\EmployeeSmartSearchUnavailableException;
use App\Models\User;
use App\Support\Settings\AiRuntimeConfig;
use App\Support\Settings\SettingKey;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\AiManager;
use Spatie\Activitylog\Models\Activity;
use Throwable;

class AiSettingsService
{
    public const PROVIDER_OPENAI = 'openai';

    public const PROVIDER_OPENROUTER = 'openrouter';

    /** @var list<string> */
    public const PROVIDERS = [
        self::PROVIDER_OPENAI,
        self::PROVIDER_OPENROUTER,
    ];

    public function __construct(private SettingService $settings) {}

    public function isSmartSearchEnabled(): bool
    {
        $stored = $this->settings->get(SettingKey::AiSmartSearchEnabled);

        if ($stored !== null) {
            return filter_var($stored, FILTER_VALIDATE_BOOLEAN);
        }

        return filter_var(config('employee-smart-search.enabled'), FILTER_VALIDATE_BOOLEAN);
    }

    public function selectedProvider(): string
    {
        $stored = $this->storedProvider();

        if ($stored === '') {
            return self::PROVIDER_OPENAI;
        }

        if (! in_array($stored, self::PROVIDERS, true)) {
            throw EmployeeSmartSearchUnavailableException::providerFailed();
        }

        return $stored;
    }

    public function selectedModel(): ?string
    {
        return $this->configuredModelFor($this->selectedProvider());
    }

    public function modelFor(string $provider): ?string
    {
        return $this->configuredModelFor($provider);
    }

    public function configuredModelFor(string $provider): ?string
    {
        $key = $provider === self::PROVIDER_OPENROUTER
            ? SettingKey::AiOpenRouterModel
            : SettingKey::AiOpenAiModel;

        $model = trim((string) $this->settings->get($key, ''));

        return $model !== '' ? $model : null;
    }

    private function defaultModelFor(string $provider): ?string
    {
        $defaults = config('employee-smart-search.default_models', []);
        $default = is_array($defaults) ? ($defaults[$provider] ?? null) : null;

        if (! is_string($default)) {
            return null;
        }

        $trimmed = trim($default);

        return $trimmed !== '' ? $trimmed : null;
    }

    public function effectiveModelFor(string $provider): ?string
    {
        return $this->configuredModelFor($provider) ?? $this->defaultModelFor($provider);
    }

    /** @return array<string, mixed> */
    public function forSettingsPage(): array
    {
        $stored = $this->storedProvider();
        $provider = in_array($stored, self::PROVIDERS, true)
            ? $stored
            : self::PROVIDER_OPENAI;

        return [
            'enabled' => $this->isSmartSearchEnabled(),
            'provider' => $provider,
            'openai' => [
                'has_api_key' => $this->hasApiKey(self::PROVIDER_OPENAI),
                'model' => $this->configuredModelFor(self::PROVIDER_OPENAI) ?? '',
            ],
            'openrouter' => [
                'has_api_key' => $this->hasApiKey(self::PROVIDER_OPENROUTER),
                'model' => $this->configuredModelFor(self::PROVIDER_OPENROUTER) ?? '',
            ],
            'default_models' => [
                'openai' => $this->defaultModelFor(self::PROVIDER_OPENAI) ?? '',
                'openrouter' => $this->defaultModelFor(self::PROVIDER_OPENROUTER) ?? '',
            ],
        ];
    }

    /**
     * @param  array{
     *     enabled: bool,
     *     provider: string,
     *     openai_api_key?: string|null,
     *     openai_model?: string|null,
     *     openrouter_api_key?: string|null,
     *     openrouter_model?: string|null
     * }  $payload
     */
    public function storeFromPayload(array $payload, User $actor): void
    {
        $provider = $payload['provider'];

        $previousEnabled = $this->isSmartSearchEnabled();
        $previousProvider = $this->previousProviderForAudit();
        $previousOpenAiModel = $this->configuredModelFor(self::PROVIDER_OPENAI);
        $previousOpenRouterModel = $this->configuredModelFor(self::PROVIDER_OPENROUTER);

        $openaiModel = trim((string) ($payload['openai_model'] ?? ''));
        $openrouterModel = trim((string) ($payload['openrouter_model'] ?? ''));
        $openaiKeyReplaced = filled($payload['openai_api_key'] ?? null);
        $openrouterKeyReplaced = filled($payload['openrouter_api_key'] ?? null);

        DB::transaction(function () use (
            $payload,
            $actor,
            $provider,
            $previousEnabled,
            $previousProvider,
            $previousOpenAiModel,
            $previousOpenRouterModel,
            $openaiModel,
            $openrouterModel,
            $openaiKeyReplaced,
            $openrouterKeyReplaced,
        ): void {
            $this->settings->set(
                SettingKey::AiSmartSearchEnabled,
                $payload['enabled'] ? '1' : '0',
            );
            $this->settings->set(SettingKey::AiProvider, $provider);
            $this->settings->set(SettingKey::AiOpenAiModel, $openaiModel);
            $this->settings->set(SettingKey::AiOpenRouterModel, $openrouterModel);

            if ($openaiKeyReplaced) {
                $this->settings->set(
                    SettingKey::AiOpenAiApiKey,
                    Crypt::encryptString((string) $payload['openai_api_key']),
                    'encrypted',
                );
            }

            if ($openrouterKeyReplaced) {
                $this->settings->set(
                    SettingKey::AiOpenRouterApiKey,
                    Crypt::encryptString((string) $payload['openrouter_api_key']),
                    'encrypted',
                );
            }

            activity('platform')
                ->event('updated')
                ->causedBy($actor)
                ->withProperties([
                    'scope' => 'platform',
                    'enabled' => $payload['enabled'],
                    'provider' => $provider,
                    'openai_model' => $openaiModel !== '' ? $openaiModel : null,
                    'openrouter_model' => $openrouterModel !== '' ? $openrouterModel : null,
                    'enabled_changed' => $previousEnabled !== $payload['enabled'],
                    'provider_changed' => $previousProvider !== $provider,
                    'openai_model_changed' => $previousOpenAiModel !== ($openaiModel !== '' ? $openaiModel : null),
                    'openrouter_model_changed' => $previousOpenRouterModel !== ($openrouterModel !== '' ? $openrouterModel : null),
                    'openai_credential_replaced' => $openaiKeyReplaced,
                    'openrouter_credential_replaced' => $openrouterKeyReplaced,
                ])
                ->tap(function (Activity $activity): void {
                    $activity->company_id = null;
                })
                ->log('updated platform AI settings');
        });

        $this->settings->clearCache();
    }

    public function applySelectedProviderToRuntime(): AiRuntimeConfig
    {
        $runtime = $this->runtimeConfig();

        config([
            'ai.default' => $runtime->provider,
            'ai.providers.'.$runtime->provider.'.key' => $runtime->apiKey,
        ]);

        app(AiManager::class)->forgetInstance($runtime->provider);

        return $runtime;
    }

    public function runtimeConfig(): AiRuntimeConfig
    {
        $provider = $this->selectedProvider();
        $apiKey = $this->resolvedApiKey($provider);

        if ($apiKey === '') {
            throw EmployeeSmartSearchUnavailableException::missingCredentials();
        }

        return new AiRuntimeConfig(
            provider: $provider,
            model: $this->effectiveModelFor($provider),
            apiKey: $apiKey,
        );
    }

    public function hasApiKey(string $provider): bool
    {
        return $this->resolvedApiKey($provider) !== '';
    }

    public function hasStoredApiKey(string $provider): bool
    {
        return filled($this->decryptStoredApiKey($this->apiKeySettingKey($provider)));
    }

    public function providerLabel(string $provider): string
    {
        return $provider === self::PROVIDER_OPENROUTER ? 'OpenRouter' : 'OpenAI';
    }

    private function storedProvider(): string
    {
        return strtolower(trim((string) $this->settings->get(SettingKey::AiProvider, '')));
    }

    private function previousProviderForAudit(): string
    {
        $stored = $this->storedProvider();

        if ($stored === '') {
            return self::PROVIDER_OPENAI;
        }

        return $stored;
    }

    private function resolvedApiKey(string $provider): string
    {
        $stored = $this->decryptStoredApiKey($this->apiKeySettingKey($provider));

        if ($stored !== null && $stored !== '') {
            return $stored;
        }

        $fallback = config('ai.providers.'.$provider.'.key');

        return is_string($fallback) ? trim($fallback) : '';
    }

    private function decryptStoredApiKey(string $settingKey): ?string
    {
        $encrypted = $this->settings->get($settingKey);

        if (! filled($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    private function apiKeySettingKey(string $provider): string
    {
        return $provider === self::PROVIDER_OPENROUTER
            ? SettingKey::AiOpenRouterApiKey
            : SettingKey::AiOpenAiApiKey;
    }
}
