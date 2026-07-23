<?php

namespace App\Http\Requests\Settings;

use App\Services\Settings\MailSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TestApplicationMailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('settings.application.update');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'recipient' => ['required', 'email', 'max:255'],
            'host' => ['nullable', 'string', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['nullable', 'string', Rule::in(['ssl', 'tls', 'none'])],
            'from_address' => ['nullable', 'email', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
            'attachment' => ['nullable', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,doc,docx'],
        ];
    }

    /** @return array<string, mixed>|null */
    public function smtpOverride(): ?array
    {
        $host = trim((string) $this->input('host'));

        if ($host === '') {
            return null;
        }

        return [
            'host' => $host,
            'port' => (int) $this->input('port', 587),
            'username' => (string) $this->input('username', ''),
            'password' => $this->input('password'),
            'encryption' => (string) $this->input('encryption', 'tls'),
            'from_address' => (string) $this->input('from_address', ''),
            'from_name' => (string) $this->input('from_name', ''),
        ];
    }

    public function assertCanSend(): void
    {
        $override = $this->smtpOverride();

        if ($override !== null) {
            return;
        }

        $mailSettings = app(MailSettingsService::class);

        if ($mailSettings->isConfigured() || filled(env('MAIL_HOST'))) {
            return;
        }

        throw ValidationException::withMessages([
            'host' => 'Save SMTP settings or enter a mail host before sending a test email.',
        ]);
    }
}
