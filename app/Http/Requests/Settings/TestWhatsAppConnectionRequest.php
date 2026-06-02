<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class TestWhatsAppConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings.integrations.whatsapp.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'business_account_id' => ['required', 'string', 'max:255'],
            'phone_number_id' => ['required', 'string', 'max:255'],
            'access_token' => ['nullable', 'string', 'max:4096'],
            'app_id' => ['required', 'string', 'max:255'],
            'app_secret' => ['nullable', 'string', 'max:4096'],
            'webhook_verify_token' => ['nullable', 'string', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function credentialsOverride(): array
    {
        return $this->only([
            'business_account_id',
            'phone_number_id',
            'access_token',
            'app_id',
            'app_secret',
            'webhook_verify_token',
            'enabled',
        ]);
    }
}
