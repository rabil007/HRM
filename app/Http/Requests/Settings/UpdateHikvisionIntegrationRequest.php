<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateHikvisionIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings.integrations.hikvision.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $requiredWhenEnabled = $this->boolean('enabled') ? 'required' : 'nullable';

        return [
            'api_host' => [$requiredWhenEnabled, 'string', 'max:255', 'url'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'api_secret' => ['nullable', 'string', 'max:255'],
            'enabled' => ['required', 'boolean'],
            'webhook_enabled' => ['sometimes', 'boolean'],
            'webhook_verify_token' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    public function settingsPayload(): array
    {
        return $this->only([
            'api_host',
            'api_key',
            'api_secret',
            'enabled',
            'webhook_enabled',
            'webhook_verify_token',
        ]);
    }
}
