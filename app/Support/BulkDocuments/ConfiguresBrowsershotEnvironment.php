<?php

namespace App\Support\BulkDocuments;

final class ConfiguresBrowsershotEnvironment
{
    public static function apply(): string
    {
        $cacheDir = self::resolveCacheDir();

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

    public static function resolveCacheDir(): string
    {
        $default = storage_path('app/puppeteer');
        $configured = config('services.browsershot.puppeteer_cache_dir');

        if (! is_string($configured) || $configured === '') {
            return $default;
        }

        if (self::cacheDirHasChrome($configured)) {
            return $configured;
        }

        if (self::cacheDirHasChrome($default)) {
            return $default;
        }

        return $configured;
    }

    private static function cacheDirHasChrome(string $cacheDir): bool
    {
        foreach (glob($cacheDir.'/chrome-headless-shell/*/chrome-headless-shell-*/chrome-headless-shell') ?: [] as $path) {
            if (is_executable($path)) {
                return true;
            }
        }

        return false;
    }
}
