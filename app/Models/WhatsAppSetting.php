<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsAppSetting extends Model
{
    protected $table = 'whatsapp_settings';

    protected $fillable = [
        'business_account_id',
        'phone_number_id',
        'access_token',
        'app_id',
        'app_secret',
        'webhook_verify_token',
        'enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'app_secret' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return self::query()->firstOrCreate(
            ['id' => 1],
            [
                'enabled' => false,
                'webhook_verify_token' => 'HERD_OMS_WHATSAPP_VERIFY_TOKEN',
            ],
        );
    }

    public function isConfigured(): bool
    {
        if (! $this->enabled) {
            return false;
        }

        return filled($this->business_account_id)
            && filled($this->phone_number_id)
            && filled($this->access_token)
            && filled($this->app_id)
            && filled($this->app_secret)
            && filled($this->webhook_verify_token);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSettingsPageArray(): array
    {
        return [
            'business_account_id' => $this->business_account_id ?? '',
            'phone_number_id' => $this->phone_number_id ?? '',
            'app_id' => $this->app_id ?? '',
            'access_token' => $this->access_token ?? '',
            'app_secret' => $this->app_secret ?? '',
            'webhook_verify_token' => $this->webhook_verify_token ?? '',
            'enabled' => (bool) $this->enabled,
            'has_access_token' => filled($this->access_token),
            'has_app_secret' => filled($this->app_secret),
            'is_configured' => $this->isConfigured(),
            'webhook_status' => filled($this->webhook_verify_token) ? 'configured' : 'not_configured',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function storeFromValidated(array $data): void
    {
        $this->business_account_id = $data['business_account_id'] ?? null;
        $this->phone_number_id = $data['phone_number_id'] ?? null;
        $this->app_id = $data['app_id'] ?? null;
        $this->webhook_verify_token = $data['webhook_verify_token'] ?? null;
        $this->enabled = (bool) ($data['enabled'] ?? false);

        if (filled($data['access_token'] ?? null)) {
            $this->access_token = (string) $data['access_token'];
        }

        if (filled($data['app_secret'] ?? null)) {
            $this->app_secret = (string) $data['app_secret'];
        }

        $this->save();
    }
}
