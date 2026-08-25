<?php

namespace App\Http\Requests\Settings;

use App\Services\Settings\AiSettingsService;
use App\Support\Platform\PlatformAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateApplicationAiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return PlatformAuthorization::canManage($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'provider' => ['required', 'string', Rule::in(AiSettingsService::PROVIDERS)],
            'openai_api_key' => ['nullable', 'string', 'max:4096'],
            'openai_model' => ['nullable', 'string', 'max:255'],
            'openrouter_api_key' => ['nullable', 'string', 'max:4096'],
            'openrouter_model' => ['nullable', 'string', 'max:255'],
            'company_id' => ['prohibited'],
        ];
    }

    /**
     * @return array{
     *     enabled: bool,
     *     provider: string,
     *     openai_api_key: string|null,
     *     openai_model: string|null,
     *     openrouter_api_key: string|null,
     *     openrouter_model: string|null
     * }
     */
    public function settingPayload(): array
    {
        return [
            'enabled' => $this->boolean('enabled'),
            'provider' => $this->string('provider')->toString(),
            'openai_api_key' => $this->filled('openai_api_key')
                ? trim((string) $this->input('openai_api_key'))
                : null,
            'openai_model' => $this->string('openai_model')->trim()->toString(),
            'openrouter_api_key' => $this->filled('openrouter_api_key')
                ? trim((string) $this->input('openrouter_api_key'))
                : null,
            'openrouter_model' => $this->string('openrouter_model')->trim()->toString(),
        ];
    }
}
