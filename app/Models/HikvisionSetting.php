<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HikvisionSetting extends Model
{
    protected $table = 'hikvision_settings';

    protected $fillable = [
        'api_host',
        'api_key',
        'api_secret',
        'enabled',
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

        $host = filled($this->api_host) ? $this->api_host : (string) env('HIKVISION_API_HOST', '');
        $key = filled($this->api_key) ? $this->api_key : (string) env('HIKVISION_API_KEY', '');
        $secret = filled($this->api_secret) ? $this->api_secret : (string) env('HIKVISION_API_SECRET', '');

        return filled($host) && filled($key) && filled($secret);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSettingsPageArray(): array
    {
        $hasStoredHost = filled($this->api_host);
        $hasStoredKey = filled($this->api_key);
        $hasStoredSecret = filled($this->api_secret);
        $hasStoredCredentials = $this->hasStoredCredentials();

        $envHost = (string) env('HIKVISION_API_HOST', '');
        $envKey = (string) env('HIKVISION_API_KEY', '');
        $envSecret = (string) env('HIKVISION_API_SECRET', '');
        $hasEnvCredentials = filled($envHost) && filled($envKey) && filled($envSecret);

        return [
            'api_host' => $hasStoredHost ? (string) $this->api_host : $envHost,
            'enabled' => (bool) $this->enabled,
            'has_api_key' => $hasStoredKey || filled($envKey),
            'has_api_secret' => $hasStoredSecret || filled($envSecret),
            'uses_env_fallback' => ! $hasStoredCredentials && $hasEnvCredentials,
            'is_configured' => $this->isConfigured(),
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

        $this->save();
    }
}
