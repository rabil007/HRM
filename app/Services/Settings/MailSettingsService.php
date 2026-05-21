<?php

namespace App\Services\Settings;

use App\Support\Settings\SettingKey;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MailSettingsService
{
    public function __construct(private SettingService $settings) {}

    public function isConfigured(): bool
    {
        return filled($this->settings->get(SettingKey::MailHost));
    }

    /** @return array<string, mixed> */
    public function forSettingsPage(): array
    {
        return [
            'host' => $this->settings->get(SettingKey::MailHost) ?? (string) env('MAIL_HOST', ''),
            'port' => (int) ($this->settings->get(SettingKey::MailPort) ?? env('MAIL_PORT', 587)),
            'username' => $this->settings->get(SettingKey::MailUsername) ?? (string) env('MAIL_USERNAME', ''),
            'encryption' => $this->settings->get(SettingKey::MailEncryption) ?? $this->guessEncryptionFromEnv(),
            'from_address' => $this->settings->get(SettingKey::MailFromAddress) ?? (string) env('MAIL_FROM_ADDRESS', ''),
            'from_name' => $this->settings->get(SettingKey::MailFromName) ?? (string) env('MAIL_FROM_NAME', config('app.name', 'Laravel')),
            'has_password' => $this->hasStoredPassword(),
            'is_configured' => $this->isConfigured(),
            'uses_env_fallback' => ! $this->isConfigured(),
        ];
    }

    public function applyToRuntimeConfig(?array $override = null): void
    {
        $config = $this->resolveConfig($override);

        if ($config['host'] === '') {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $config['host'],
            'mail.mailers.smtp.port' => $config['port'],
            'mail.mailers.smtp.username' => $config['username'] ?: null,
            'mail.mailers.smtp.password' => $config['password'] ?: null,
            'mail.mailers.smtp.scheme' => $config['scheme'],
            'mail.from.address' => $config['from_address'] ?: config('mail.from.address'),
            'mail.from.name' => $config['from_name'] ?: config('mail.from.name'),
        ]);
    }

    public function sendTestEmail(string $recipient, ?array $override = null): void
    {
        $this->applyToRuntimeConfig($override);

        $appName = $this->settings->appName();

        Mail::raw(
            "This is a test email from {$appName}.\n\nSent at: ".now()->toDateTimeString(),
            function ($message) use ($recipient, $appName): void {
                $message->to($recipient)->subject("{$appName} — SMTP test");
            },
        );
    }

    /**
     * @return array{host: string, port: int, username: string, password: string|null, encryption: string, from_address: string, from_name: string, scheme: string|null}
     */
    public function resolveConfig(?array $override = null): array
    {
        $override = $override ?? [];

        $host = (string) ($override['host'] ?? $this->settings->get(SettingKey::MailHost) ?? env('MAIL_HOST', ''));
        $port = (int) ($override['port'] ?? $this->settings->get(SettingKey::MailPort) ?? env('MAIL_PORT', 587));
        $username = (string) ($override['username'] ?? $this->settings->get(SettingKey::MailUsername) ?? env('MAIL_USERNAME', ''));
        $encryption = (string) ($override['encryption'] ?? $this->settings->get(SettingKey::MailEncryption) ?? $this->guessEncryptionFromEnv());
        $fromAddress = (string) ($override['from_address'] ?? $this->settings->get(SettingKey::MailFromAddress) ?? env('MAIL_FROM_ADDRESS', ''));
        $fromName = (string) ($override['from_name'] ?? $this->settings->get(SettingKey::MailFromName) ?? env('MAIL_FROM_NAME', config('app.name', 'Laravel')));

        $password = array_key_exists('password', $override)
            ? ($override['password'] !== '' && $override['password'] !== null ? (string) $override['password'] : $this->decryptStoredPassword())
            : $this->decryptStoredPassword();

        if ($password === null || $password === '') {
            $password = (string) env('MAIL_PASSWORD', '');
        }

        return [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password !== '' ? $password : null,
            'encryption' => $encryption,
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'scheme' => $this->schemeFor($encryption, $port),
        ];
    }

    public function storeFromPayload(array $payload): void
    {
        $values = [
            SettingKey::MailHost => $payload['host'],
            SettingKey::MailPort => (string) $payload['port'],
            SettingKey::MailUsername => $payload['username'] ?? '',
            SettingKey::MailEncryption => $payload['encryption'],
            SettingKey::MailFromAddress => $payload['from_address'],
            SettingKey::MailFromName => $payload['from_name'],
        ];

        if (filled($payload['password'] ?? null)) {
            $values[SettingKey::MailPassword] = Crypt::encryptString((string) $payload['password']);
        }

        foreach ($values as $key => $value) {
            $type = $key === SettingKey::MailPassword ? 'encrypted' : 'string';
            $this->settings->set($key, $value, $type);
        }

        $this->applyToRuntimeConfig();
    }

    public function hasStoredPassword(): bool
    {
        return filled($this->settings->get(SettingKey::MailPassword));
    }

    private function decryptStoredPassword(): ?string
    {
        $encrypted = $this->settings->get(SettingKey::MailPassword);

        if (! filled($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    private function guessEncryptionFromEnv(): string
    {
        $envEncryption = strtolower((string) env('MAIL_ENCRYPTION', ''));

        if (in_array($envEncryption, ['ssl', 'tls', 'none'], true)) {
            return $envEncryption;
        }

        return (int) env('MAIL_PORT', 587) === 465 ? 'ssl' : 'tls';
    }

    private function schemeFor(string $encryption, int $port): ?string
    {
        return match ($encryption) {
            'ssl' => 'smtps',
            'tls' => 'smtp',
            'none' => 'smtp',
            default => $port === 465 ? 'smtps' : 'smtp',
        };
    }
}
