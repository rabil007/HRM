<?php

namespace App\Support\BulkDocuments;

final class EnsuresBrowsershotChromePermissions
{
    public static function apply(string $cacheDir): void
    {
        $homeDir = $cacheDir.'/home';

        if (! is_dir($homeDir)) {
            mkdir($homeDir, 0755, true);
        }

        foreach (glob($cacheDir.'/chrome-headless-shell/*/chrome-headless-shell-*', GLOB_ONLYDIR) ?: [] as $chromeDir) {
            foreach (glob($chromeDir.'/*') ?: [] as $path) {
                if (is_file($path)) {
                    chmod($path, 0755);
                }
            }
        }
    }
}
