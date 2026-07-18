<?php

namespace App\Support\Settings;

use App\Models\Company;
use App\Services\Settings\SettingService;
use Illuminate\Support\Facades\Storage;

final class SharedSettingsPresenter
{
    public function __construct(
        private SettingService $settings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forInertia(?int $companyId): array
    {
        $platform = $this->platform();
        $company = $this->company($companyId);

        return [
            'platform' => $platform,
            'company' => $company,
            // Deprecated flat keys — prefer settings.platform / settings.company.
            'app_name' => $platform['app_name'],
            'company_name' => $company['name'] ?? $this->legacyString(SettingKey::CompanyName),
            'support_email' => $platform['support_email'],
            'support_phone' => $platform['support_phone'],
            'company_address' => $company['address'] ?? $this->legacyString(SettingKey::CompanyAddress),
            'timezone' => $company['timezone'] ?? $platform['fallback_timezone'],
            'currency' => $company['currency']['code'] ?? $this->legacyString(SettingKey::Currency, 'AED'),
            'date_format' => $platform['default_date_format'],
            'branding' => $platform['branding'],
            'preferences' => $platform['preferences'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function platform(): array
    {
        $value = fn (string $key, string $default = ''): string => (string) (
            ($this->settings->get($key) ?? '') === ''
                ? (SettingKey::defaults()[$key] ?? $default)
                : $this->settings->get($key)
        );

        return [
            'app_name' => $value(SettingKey::AppName, (string) config('app.name', 'Laravel')),
            'support_email' => $value(SettingKey::SupportEmail),
            'support_phone' => $value(SettingKey::SupportPhone),
            'fallback_timezone' => ApplicationTimezone::identifier(),
            'default_date_format' => $value(SettingKey::DateFormat, 'Y-m-d'),
            'branding' => $this->settings->brandingUrls(),
            'preferences' => [
                'primary_color' => $value(SettingKey::PrimaryColor, '#6366f1'),
                'accent_color' => $value(SettingKey::AccentColor, '#8b5cf6'),
                'sidebar_compact_default' => $value(SettingKey::SidebarCompactDefault, '0') === '1',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function company(?int $companyId): ?array
    {
        if ($companyId === null || $companyId < 1) {
            return null;
        }

        $company = Company::query()
            ->with('currency:id,code,symbol')
            ->select([
                'id',
                'name',
                'email',
                'phone',
                'address',
                'website',
                'timezone',
                'currency_id',
                'logo',
            ])
            ->find($companyId);

        if ($company === null) {
            return null;
        }

        $currency = CompanyCurrency::forCompany($company);
        $logoUrl = null;

        if (filled($company->logo) && Storage::disk('public')->exists((string) $company->logo)) {
            $logoUrl = Storage::disk('public')->url((string) $company->logo);
        }

        return [
            'id' => $company->id,
            'name' => (string) $company->name,
            'email' => $company->email,
            'phone' => $company->phone,
            'address' => $company->address,
            'website' => $company->website,
            'timezone' => CompanyTimezone::forCompany($company),
            'currency' => [
                'code' => $currency['code'],
                'symbol' => $currency['symbol'],
            ],
            'logo_url' => $logoUrl,
        ];
    }

    private function legacyString(string $key, string $default = ''): string
    {
        $value = $this->settings->get($key, $default);

        return (string) ($value ?? $default);
    }
}
