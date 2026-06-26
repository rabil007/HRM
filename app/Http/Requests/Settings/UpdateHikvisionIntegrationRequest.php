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
            'events_fetch_schedule_enabled' => ['sometimes', 'boolean'],
            'events_fetch_schedule_at' => [
                'nullable',
                'string',
                'regex:/^([01]\d|2[0-3]):[0-5]\d$/',
                'required_if:events_fetch_schedule_enabled,true,1',
            ],
            'events_evening_fetch_schedule_enabled' => ['sometimes', 'boolean'],
            'events_evening_fetch_schedule_at' => [
                'nullable',
                'string',
                'regex:/^([01]\d|2[0-3]):[0-5]\d$/',
                'required_if:events_evening_fetch_schedule_enabled,true,1',
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'events_fetch_schedule_at.required_if' => 'Choose a daily fetch time when automatic fetch is enabled.',
            'events_fetch_schedule_at.regex' => 'Use 24-hour time in HH:MM format (e.g. 18:00).',
            'events_evening_fetch_schedule_at.required_if' => 'Choose an evening fetch time when evening fetch is enabled.',
            'events_evening_fetch_schedule_at.regex' => 'Use 24-hour time in HH:MM format (e.g. 20:00).',
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
            'events_fetch_schedule_enabled',
            'events_fetch_schedule_at',
            'events_evening_fetch_schedule_enabled',
            'events_evening_fetch_schedule_at',
        ]);
    }
}
