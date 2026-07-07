<?php

namespace App\Support\Media;

use App\Models\Company;
use App\Services\Settings\SettingService;
use App\Support\Settings\SettingKey;
use Illuminate\Support\Facades\Storage;

final class CompanyLogoDataUri
{
    /** @var array<string, ?string> */
    private static array $resolvedByCompany = [];

    public static function resolve(?Company $company, ?SettingService $settings = null): ?string
    {
        $cacheKey = (string) ($company?->id ?? 'global').'|'.($company?->logo ?? '');

        if (array_key_exists($cacheKey, self::$resolvedByCompany)) {
            return self::$resolvedByCompany[$cacheKey];
        }

        $settings ??= app(SettingService::class);
        $resolved = null;

        if (filled($company?->logo)) {
            $embedded = self::fromPublicDiskPath((string) $company->logo);

            if ($embedded !== null) {
                $resolved = $embedded;
            }
        }

        if ($resolved === null) {
            foreach ([SettingKey::MainLogo, SettingKey::EmailBrandingLogo, SettingKey::SidebarLogo, SettingKey::LoginLogo] as $key) {
                $path = $settings->get($key);

                if (filled($path)) {
                    $embedded = self::fromPublicDiskPath((string) $path);

                    if ($embedded !== null) {
                        $resolved = $embedded;

                        break;
                    }
                }
            }
        }

        self::$resolvedByCompany[$cacheKey] = $resolved;

        return $resolved;
    }

    public static function fromPublicDiskPath(string $path): ?string
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            return null;
        }

        return self::fromFilePath($disk->path($path));
    }

    private static function fromFilePath(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $mimeType = self::detectImageMimeType($path, $contents);

        if ($mimeType === null) {
            return null;
        }

        return 'data:'.$mimeType.';base64,'.base64_encode($contents);
    }

    private static function detectImageMimeType(string $path, string $contents): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $detected = finfo_buffer($finfo, $contents);
        finfo_close($finfo);

        if (is_string($detected) && str_starts_with($detected, 'image/')) {
            return $detected;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => null,
        };
    }
}
