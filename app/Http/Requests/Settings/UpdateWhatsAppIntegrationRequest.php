<?php

namespace App\Http\Requests\Settings;

use App\Models\WhatsAppSetting;
use Illuminate\Foundation\Http\FormRequest;

class UpdateWhatsAppIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings.integrations.whatsapp.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $requiredWhenEnabled = $this->boolean('enabled') ? 'required' : 'nullable';
        $requiresWebhookToken = $this->boolean('enabled')
            && ! filled(WhatsAppSetting::current()->webhook_verify_token);
        $webhookTokenRule = $requiresWebhookToken ? 'required' : 'nullable';

        return [
            'business_account_id' => [$requiredWhenEnabled, 'string', 'max:255'],
            'phone_number_id' => [$requiredWhenEnabled, 'string', 'max:255'],
            'access_token' => ['nullable', 'string', 'max:4096'],
            'app_id' => [$requiredWhenEnabled, 'string', 'max:255'],
            'app_secret' => ['nullable', 'string', 'max:4096'],
            'webhook_verify_token' => [$webhookTokenRule, 'string', 'max:255'],
            'enabled' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function settingsPayload(): array
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
