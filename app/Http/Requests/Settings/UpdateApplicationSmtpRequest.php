<?php

namespace App\Http\Requests\Settings;

use App\Support\Settings\SettingKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateApplicationSmtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings.application.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['required', 'string', Rule::in(['ssl', 'tls', 'none'])],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
            'email_branding_logo' => ['nullable', 'file', 'max:2048', 'mimes:png,jpg,jpeg,svg'],
            'mail_footer_tagline' => ['nullable', 'string', 'max:255'],
            'mail_footer_website' => ['nullable', 'string', 'max:255'],
            'mail_footer_certifications' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function emailFooterPayload(): array
    {
        return [
            SettingKey::MailFooterTagline => $this->string('mail_footer_tagline')->toString(),
            SettingKey::MailFooterWebsite => $this->string('mail_footer_website')->toString(),
            SettingKey::MailFooterCertifications => $this->string('mail_footer_certifications')->toString(),
        ];
    }

    /** @return array<string, mixed> */
    public function smtpPayload(): array
    {
        return $this->only([
            'host',
            'port',
            'username',
            'password',
            'encryption',
            'from_address',
            'from_name',
        ]);
    }
}
