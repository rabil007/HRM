<?php

namespace App\Support\Settings;

final class SettingKey
{
    public const AppName = 'app_name';

    /** @deprecated Prefer Company model name. Kept as legacy fallback. */
    public const CompanyName = 'company_name';

    public const SupportEmail = 'support_email';

    public const SupportPhone = 'support_phone';

    /** @deprecated Prefer Company model address. Kept as legacy fallback. */
    public const CompanyAddress = 'company_address';

    /** Platform fallback timezone. Prefer CompanyTimezone for tenant operations. */
    public const Timezone = 'timezone';

    /** @deprecated Prefer Company currency relation. Kept as legacy fallback. */
    public const Currency = 'currency';

    /** Platform default display date format. */
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

    /** @deprecated Prefer CompanyDocumentSetting signature_path. Kept as legacy fallback. */
    public const SalaryCertificateSignature = 'salary_certificate_signature';

    /** @deprecated Prefer CompanyDocumentSetting stamp_path. Kept as legacy fallback. */
    public const SalaryCertificateStamp = 'salary_certificate_stamp';

    public const MailHost = 'mail_host';

    public const MailPort = 'mail_port';

    public const MailUsername = 'mail_username';

    public const MailPassword = 'mail_password';

    public const MailEncryption = 'mail_encryption';

    public const MailFromAddress = 'mail_from_address';

    public const MailFromName = 'mail_from_name';

    public const MailFooterTagline = 'mail_footer_tagline';

    public const MailFooterWebsite = 'mail_footer_website';

    public const MailFooterCertifications = 'mail_footer_certifications';

    public const BulkDocumentSignaturePlacementSalaryDeclaration = 'bulk_document_signature_placement_salary_declaration';

    /** @return list<string> */
    public static function encryptedKeys(): array
    {
        return [
            self::MailPassword,
        ];
    }

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
            self::SalaryCertificateSignature,
            self::SalaryCertificateStamp,
        ];
    }

    /** @return list<string> */
    public static function legacyCompanyIdentityKeys(): array
    {
        return [
            self::CompanyName,
            self::CompanyAddress,
            self::Currency,
            self::SalaryCertificateSignature,
            self::SalaryCertificateStamp,
        ];
    }

    /** @return list<string> */
    public static function platformBrandingFileKeys(): array
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
            self::MailFooterTagline => 'Your Complete Marine Solutions',
            self::MailFooterWebsite => 'www.overseas-ms.com',
            self::MailFooterCertifications => 'ISO 9001:2015 | ISO 14001:2015 | ISO 45001:2018 | ICV Certified',
        ];
    }
}
