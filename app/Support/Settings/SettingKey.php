<?php

namespace App\Support\Settings;

final class SettingKey
{
    public const AppName = 'app_name';

    public const CompanyName = 'company_name';

    public const SupportEmail = 'support_email';

    public const SupportPhone = 'support_phone';

    public const CompanyAddress = 'company_address';

    public const Timezone = 'timezone';

    public const Currency = 'currency';

    public const DateFormat = 'date_format';

    public const MainLogo = 'main_logo';

    public const SidebarLogo = 'sidebar_logo';

    public const LoginLogo = 'login_logo';

    public const Favicon = 'favicon';

    public const PrimaryColor = 'primary_color';

    public const AccentColor = 'accent_color';

    public const LoginBackground = 'login_background';

    public const SidebarCompactDefault = 'sidebar_compact_default';

    public const EmailBrandingLogo = 'email_branding_logo';

    /** @return list<string> */
    public static function fileKeys(): array
    {
        return [
            self::MainLogo,
            self::SidebarLogo,
            self::LoginLogo,
            self::Favicon,
            self::LoginBackground,
            self::EmailBrandingLogo,
        ];
    }

    /** @return array<string, string> */
    public static function defaults(): array
    {
        return [
            self::AppName => config('app.name', 'Laravel'),
            self::CompanyName => config('app.name', 'Laravel'),
            self::SupportEmail => '',
            self::SupportPhone => '',
            self::CompanyAddress => '',
            self::Timezone => config('app.timezone', 'UTC'),
            self::Currency => 'USD',
            self::DateFormat => 'Y-m-d',
            self::PrimaryColor => '#6366f1',
            self::AccentColor => '#8b5cf6',
            self::SidebarCompactDefault => '0',
        ];
    }
}
