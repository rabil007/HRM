<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class TestHikvisionConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings.integrations.hikvision.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'api_host' => ['required', 'string', 'max:255', 'url'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'api_secret' => ['nullable', 'string', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function credentialsOverride(): array
    {
        return $this->only([
            'api_host',
            'api_key',
            'api_secret',
            'enabled',
        ]);
    }
}
