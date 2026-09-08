<?php

namespace App\Http\Requests\Settings;

use App\Support\Platform\PlatformAuthorization;
use App\Support\Settings\SettingKey;
use Illuminate\Foundation\Http\FormRequest;

class UpdateApplicationRetentionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return PlatformAuthorization::canManage($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $dayRule = ['required', 'integer', 'min:1', 'max:3650'];

        return [
            'completed_days' => $dayRule,
            'failed_days' => $dayRule,
            'running_days' => $dayRule,
            'deleted_days' => $dayRule,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function settingPayload(): array
    {
        $validated = $this->validated();

        return [
            SettingKey::JobRunCompletedRetentionDays => (string) $validated['completed_days'],
            SettingKey::JobRunFailedRetentionDays => (string) $validated['failed_days'],
            SettingKey::JobRunRunningRetentionDays => (string) $validated['running_days'],
            SettingKey::JobRunDeletedRetentionDays => (string) $validated['deleted_days'],
        ];
    }
}
