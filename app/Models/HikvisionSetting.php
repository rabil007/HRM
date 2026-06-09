<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HikvisionSetting extends Model
{
    protected $table = 'hikvision_settings';

    public const EVENTS_FETCH_IDLE = 'idle';

    public const EVENTS_FETCH_QUEUED = 'queued';

    public const EVENTS_FETCH_RUNNING = 'running';

    public const EVENTS_FETCH_COMPLETED = 'completed';

    public const EVENTS_FETCH_FAILED = 'failed';

    protected $fillable = [
        'api_host',
        'api_key',
        'api_secret',
        'enabled',
        'persons_last_synced_at',
        'events_last_fetched_at',
        'events_fetch_status',
        'events_fetch_message',
        'events_fetch_started_at',
        'events_fetch_schedule_enabled',
        'events_fetch_schedule_at',
        'webhook_verify_token',
        'webhook_enabled',
        'webhook_callback_url',
        'webhook_registered_at',
        'webhook_last_event_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'api_secret' => 'encrypted',
            'enabled' => 'boolean',
            'persons_last_synced_at' => 'datetime',
            'events_last_fetched_at' => 'datetime',
            'events_fetch_started_at' => 'datetime',
            'events_fetch_schedule_enabled' => 'boolean',
            'webhook_enabled' => 'boolean',
            'webhook_registered_at' => 'datetime',
            'webhook_last_event_at' => 'datetime',
        ];
    }

    public function isEventsFetchProcessing(): bool
    {
        return in_array($this->events_fetch_status, [
            self::EVENTS_FETCH_QUEUED,
            self::EVENTS_FETCH_RUNNING,
        ], true);
    }

    public function beginEventsFetch(): void
    {
        $this->update([
            'events_fetch_status' => self::EVENTS_FETCH_QUEUED,
            'events_fetch_message' => null,
            'events_fetch_started_at' => now(),
        ]);
    }

    public function markEventsFetchRunning(): void
    {
        $this->update([
            'events_fetch_status' => self::EVENTS_FETCH_RUNNING,
        ]);
    }

    public function markEventsFetchCompleted(string $message): void
    {
        $this->update([
            'events_fetch_status' => self::EVENTS_FETCH_COMPLETED,
            'events_fetch_message' => $message,
            'events_last_fetched_at' => now(),
        ]);
    }

    public function markEventsFetchFailed(string $message): void
    {
        $this->update([
            'events_fetch_status' => self::EVENTS_FETCH_FAILED,
            'events_fetch_message' => $message,
        ]);
    }

    public function resolveStaleEventsFetch(int $timeoutMinutes = 3): void
    {
        if (! $this->isEventsFetchProcessing()) {
            return;
        }

        if ($this->events_fetch_started_at?->lt(now()->subMinutes($timeoutMinutes))) {
            $this->markEventsFetchFailed(
                'Fetch timed out. Confirm the server queue worker (cron) is running.',
            );
        }
    }

    /**
     * @return array{status: string, message: string|null}
     */
    public function acknowledgeFetchResult(): array
    {
        $status = (string) ($this->events_fetch_status ?? self::EVENTS_FETCH_IDLE);
        $message = $this->events_fetch_message;

        if (in_array($status, [self::EVENTS_FETCH_COMPLETED, self::EVENTS_FETCH_FAILED], true)) {
            $this->update([
                'events_fetch_status' => self::EVENTS_FETCH_IDLE,
                'events_fetch_message' => null,
            ]);
        }

        return [
            'status' => $status,
            'message' => $message,
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(
            ['id' => 1],
            [
                'enabled' => false,
            ],
        );
    }

    public function hasStoredCredentials(): bool
    {
        return filled($this->api_host)
            && filled($this->api_key)
            && filled($this->api_secret);
    }

    public function isConfigured(): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $host = filled($this->api_host) ? $this->api_host : (string) config('hikvision.api_host', '');
        $key = filled($this->api_key) ? $this->api_key : (string) config('hikvision.api_key', '');
        $secret = filled($this->api_secret) ? $this->api_secret : (string) config('hikvision.api_secret', '');

        return filled($host) && filled($key) && filled($secret);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSettingsPageArray(bool $includeWebhookToken = false): array
    {
        $hasStoredHost = filled($this->api_host);
        $hasStoredKey = filled($this->api_key);
        $hasStoredSecret = filled($this->api_secret);
        $hasStoredCredentials = $this->hasStoredCredentials();

        $envHost = (string) config('hikvision.api_host', '');
        $envKey = (string) config('hikvision.api_key', '');
        $envSecret = (string) config('hikvision.api_secret', '');
        $hasEnvCredentials = filled($envHost) && filled($envKey) && filled($envSecret);

        return [
            'api_host' => $hasStoredHost ? (string) $this->api_host : $envHost,
            'api_key' => $hasStoredKey ? (string) $this->api_key : $envKey,
            'api_secret' => $hasStoredSecret ? (string) $this->api_secret : $envSecret,
            'enabled' => (bool) $this->enabled,
            'has_api_key' => $hasStoredKey || filled($envKey),
            'has_api_secret' => $hasStoredSecret || filled($envSecret),
            'uses_env_fallback' => ! $hasStoredCredentials && $hasEnvCredentials,
            'is_configured' => $this->isConfigured(),
            'webhook_verify_token' => $includeWebhookToken ? ($this->webhook_verify_token ?? '') : '',
            'webhook_enabled' => (bool) $this->webhook_enabled,
            'webhook_registered_at' => $this->webhook_registered_at?->toIso8601String(),
            'webhook_last_event_at' => $this->webhook_last_event_at?->toIso8601String(),
            'has_webhook_verify_token' => filled($this->webhook_verify_token),
            'events_fetch_schedule_enabled' => (bool) $this->events_fetch_schedule_enabled,
            'events_fetch_schedule_at' => $this->events_fetch_schedule_at
                ?? (string) config('hikvision.events_fetch_schedule_at', '18:00'),
            'events_last_fetched_at' => $this->events_last_fetched_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function storeFromValidated(array $data): void
    {
        $this->api_host = $data['api_host'] ?? null;
        $this->enabled = (bool) ($data['enabled'] ?? false);

        if (filled($data['api_key'] ?? null)) {
            $this->api_key = (string) $data['api_key'];
        }

        if (filled($data['api_secret'] ?? null)) {
            $this->api_secret = (string) $data['api_secret'];
        }

        if (array_key_exists('webhook_verify_token', $data)) {
            $this->webhook_verify_token = filled($data['webhook_verify_token'])
                ? (string) $data['webhook_verify_token']
                : null;
        }

        if (array_key_exists('webhook_enabled', $data)) {
            $this->webhook_enabled = (bool) $data['webhook_enabled'];
        }

        if (array_key_exists('events_fetch_schedule_enabled', $data)) {
            $this->events_fetch_schedule_enabled = (bool) $data['events_fetch_schedule_enabled'];
        }

        if (array_key_exists('events_fetch_schedule_at', $data)) {
            $this->events_fetch_schedule_at = filled($data['events_fetch_schedule_at'])
                ? (string) $data['events_fetch_schedule_at']
                : null;
        }

        $this->save();
    }

    public function ensureWebhookVerifyToken(): string
    {
        if (filled($this->webhook_verify_token)) {
            return (string) $this->webhook_verify_token;
        }

        $token = bin2hex(random_bytes(8));
        $this->update(['webhook_verify_token' => $token]);

        return $token;
    }

    public function resolveWebhookSignSecret(): string
    {
        $token = trim((string) ($this->webhook_verify_token ?? ''));

        if ($token !== '' && preg_match('/^[A-Za-z0-9]{8,32}$/', $token) === 1) {
            return $token;
        }

        $secret = trim((string) ($this->api_secret ?? config('hikvision.api_secret', '')));

        if ($secret === '') {
            throw new \RuntimeException('Webhook sign secret is not configured.');
        }

        return $secret;
    }

    /**
     * Returns an explicit signSecret for config/save when the stored token is valid.
     * Otherwise Hik-Connect defaults to the integrator SecretKey.
     */
    public function webhookSignSecretForRegistration(): ?string
    {
        $token = trim((string) ($this->webhook_verify_token ?? ''));

        if ($token !== '' && preg_match('/^[A-Za-z0-9]{8,32}$/', $token) === 1) {
            return $token;
        }

        return null;
    }

    public function markWebhookRegistered(string $callbackUrl): void
    {
        $this->update([
            'webhook_callback_url' => $callbackUrl,
            'webhook_registered_at' => now(),
            'webhook_enabled' => true,
        ]);
    }

    public function markWebhookEventReceived(): void
    {
        $this->update(['webhook_last_event_at' => now()]);
    }
}
