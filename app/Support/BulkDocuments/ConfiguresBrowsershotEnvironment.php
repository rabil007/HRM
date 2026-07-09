<?php

namespace App\Support\BulkDocuments;

final class ConfiguresBrowsershotEnvironment
{
    public static function apply(): string
    {
        $configured = config('services.browsershot.puppeteer_cache_dir');
        $cacheDir = is_string($configured) && $configured !== ''
            ? $configured
            : storage_path('app/puppeteer');

        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $homeDir = $cacheDir.'/home';

        if (! is_dir($homeDir)) {
            mkdir($homeDir, 0755, true);
        }

        putenv("PUPPETEER_CACHE_DIR={$cacheDir}");
        $_ENV['PUPPETEER_CACHE_DIR'] = $cacheDir;
        $_SERVER['PUPPETEER_CACHE_DIR'] = $cacheDir;

        putenv("HOME={$homeDir}");
        $_ENV['HOME'] = $homeDir;
        $_SERVER['HOME'] = $homeDir;

        return $cacheDir;
    }
}
