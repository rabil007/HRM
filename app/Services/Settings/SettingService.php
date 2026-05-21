<?php

namespace App\Services\Settings;

use App\Models\AppSetting;
use App\Support\Settings\SettingKey;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SettingService
{
    private const CACHE_KEY = 'app.settings.all';

    private const STORAGE_DIR = 'settings';

    public function isReady(): bool
    {
        return Schema::hasTable('app_settings');
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $all = $this->all();

        if (array_key_exists($key, $all)) {
            $value = $all[$key];

            return $value === '' || $value === null ? $default : $value;
        }

        $defaults = SettingKey::defaults();

        return $defaults[$key] ?? $default;
    }

    /** @return array<string, string|null> */
    public function all(): array
    {
        if (! $this->isReady()) {
            return SettingKey::defaults();
        }

        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $stored = AppSetting::query()
                ->get(['key', 'value'])
                ->pluck('value', 'key')
                ->all();

            return array_merge(SettingKey::defaults(), $stored);
        });
    }

    public function set(string $key, ?string $value, string $type = 'string'): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'type' => $type],
        );

        $this->clearCache();
    }

    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function appName(): string
    {
        return (string) ($this->get(SettingKey::AppName) ?? config('app.name', 'Laravel'));
    }

    /** @return array<string, mixed> */
    public function forInertia(): array
    {
        return [
            'app_name' => $this->appName(),
            'company_name' => (string) $this->get(SettingKey::CompanyName, ''),
            'support_email' => (string) $this->get(SettingKey::SupportEmail, ''),
            'support_phone' => (string) $this->get(SettingKey::SupportPhone, ''),
            'company_address' => (string) $this->get(SettingKey::CompanyAddress, ''),
            'timezone' => (string) $this->get(SettingKey::Timezone, 'UTC'),
            'currency' => (string) $this->get(SettingKey::Currency, 'USD'),
            'date_format' => (string) $this->get(SettingKey::DateFormat, 'Y-m-d'),
            'branding' => $this->brandingUrls(),
            'preferences' => [
                'primary_color' => (string) $this->get(SettingKey::PrimaryColor, '#6366f1'),
                'accent_color' => (string) $this->get(SettingKey::AccentColor, '#8b5cf6'),
                'sidebar_compact_default' => $this->get(SettingKey::SidebarCompactDefault, '0') === '1',
            ],
        ];
    }

    /** @return array<string, string|null> */
    public function brandingUrls(): array
    {
        return [
            'main_logo_url' => $this->fileUrl(SettingKey::MainLogo),
            'sidebar_logo_url' => $this->fileUrl(SettingKey::SidebarLogo),
            'login_logo_url' => $this->fileUrl(SettingKey::LoginLogo),
            'favicon_url' => $this->fileUrl(SettingKey::Favicon),
            'login_background_url' => $this->fileUrl(SettingKey::LoginBackground),
            'email_branding_logo_url' => $this->fileUrl(SettingKey::EmailBrandingLogo),
        ];
    }

    public function fileUrl(string $key): ?string
    {
        $path = $this->get($key);

        if (! $path) {
            return null;
        }

        return $this->disk()->url($path);
    }

    public function storeUpload(string $key, UploadedFile $file): string
    {
        $this->deleteFile($key);

        $extension = $file->getClientOriginalExtension() ?: $file->extension();
        $filename = Str::slug($key).'-'.Str::uuid().'.'.strtolower((string) $extension);

        $path = $file->storeAs(self::STORAGE_DIR, $filename, 'public');

        $this->set($key, $path, 'file');

        return $path;
    }

    public function deleteFile(string $key): void
    {
        $path = $this->get($key);

        if ($path && $this->disk()->exists($path)) {
            $this->disk()->delete($path);
        }

        $this->set($key, null, 'file');
    }

    /** @param array<string, string|null> $values */
    public function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $type = in_array($key, SettingKey::fileKeys(), true) ? 'file' : 'string';
            $this->set($key, $value, $type);
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk('public');
    }
}
