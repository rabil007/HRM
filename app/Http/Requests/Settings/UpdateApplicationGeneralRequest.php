<?php

namespace App\Http\Requests\Settings;

use App\Support\Settings\SettingKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateApplicationGeneralRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'app_name' => ['required', 'string', 'max:255'],
            'company_name' => ['required', 'string', 'max:255'],
            'support_email' => ['nullable', 'email', 'max:255'],
            'support_phone' => ['nullable', 'string', 'max:50'],
            'company_address' => ['nullable', 'string', 'max:1000'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'currency' => ['required', 'string', 'max:10'],
            'date_format' => ['required', 'string', Rule::in(['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'M d, Y'])],
        ];
    }

    /** @return array<string, string> */
    public function settingPayload(): array
    {
        $validated = $this->validated();

        return [
            SettingKey::AppName => $validated['app_name'],
            SettingKey::CompanyName => $validated['company_name'],
            SettingKey::SupportEmail => $validated['support_email'] ?? '',
            SettingKey::SupportPhone => $validated['support_phone'] ?? '',
            SettingKey::CompanyAddress => $validated['company_address'] ?? '',
            SettingKey::Timezone => $validated['timezone'],
            SettingKey::Currency => $validated['currency'],
            SettingKey::DateFormat => $validated['date_format'],
        ];
    }
}
