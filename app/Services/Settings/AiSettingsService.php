<?php

namespace App\Services\Settings;

use App\Exceptions\EmployeeSmartSearchUnavailableException;
use App\Models\User;
use App\Support\Settings\AiRuntimeConfig;
use App\Support\Settings\SettingKey;
use Illuminate\Support\Facades\Crypt;
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
        $value = strtolower(trim((string) $this->settings->get(SettingKey::AiProvider, self::PROVIDER_OPENAI)));

        return in_array($value, self::PROVIDERS, true) ? $value : self::PROVIDER_OPENAI;
    }

    public function selectedModel(): ?string
    {
        return $this->modelFor($this->selectedProvider());
    }

    public function modelFor(string $provider): ?string
    {
        $key = $provider === self::PROVIDER_OPENROUTER
            ? SettingKey::AiOpenRouterModel
            : SettingKey::AiOpenAiModel;

        $model = trim((string) $this->settings->get($key, ''));

        return $model !== '' ? $model : null;
    }

    /** @return array<string, mixed> */
    public function forSettingsPage(): array
    {
        return [
            'enabled' => $this->isSmartSearchEnabled(),
            'provider' => $this->selectedProvider(),
            'openai' => [
                'has_api_key' => $this->hasApiKey(self::PROVIDER_OPENAI),
                'model' => $this->modelFor(self::PROVIDER_OPENAI) ?? '',
            ],
            'openrouter' => [
                'has_api_key' => $this->hasApiKey(self::PROVIDER_OPENROUTER),
                'model' => $this->modelFor(self::PROVIDER_OPENROUTER) ?? '',
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
    public function storeFromPayload(array $payload, User $actor, ?int $companyId = null): void
    {
        $provider = $payload['provider'];

        $previousEnabled = $this->isSmartSearchEnabled();
        $previousProvider = $this->selectedProvider();
        $previousOpenAiModel = $this->modelFor(self::PROVIDER_OPENAI);
        $previousOpenRouterModel = $this->modelFor(self::PROVIDER_OPENROUTER);

        $openaiModel = trim((string) ($payload['openai_model'] ?? ''));
        $openrouterModel = trim((string) ($payload['openrouter_model'] ?? ''));
        $openaiKeyReplaced = filled($payload['openai_api_key'] ?? null);
        $openrouterKeyReplaced = filled($payload['openrouter_api_key'] ?? null);

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
            ->tap(function (Activity $activity) use ($companyId): void {
                $activity->company_id = $companyId && $companyId > 0 ? (int) $companyId : null;
            })
            ->log('updated platform AI settings');
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
            model: $this->modelFor($provider),
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
