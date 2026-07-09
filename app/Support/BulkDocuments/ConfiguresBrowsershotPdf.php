<?php

namespace App\Support\BulkDocuments;

use Spatie\Browsershot\Browsershot;

final class ConfiguresBrowsershotPdf
{
    /**
     * Chromium flags for shared hosting (Hostinger, Docker, low-memory VPS).
     *
     * @return list<string>
     */
    public static function chromiumArguments(): array
    {
        return [
            'disable-dev-shm-usage',
            'disable-gpu',
            'disable-setuid-sandbox',
            'no-zygote',
            'single-process',
        ];
    }

    public static function apply(Browsershot $shot): Browsershot
    {
        ConfiguresBrowsershotEnvironment::apply();

        $binaries = ResolvesBrowsershotBinaries::resolve();

        $shot->setNodeModulePath(base_path('node_modules'))
            ->setNodeBinary($binaries['node'])
            ->setNpmBinary($binaries['npm'])
            ->noSandbox()
            ->addChromiumArguments(self::chromiumArguments());

        if ($binaries['chrome'] !== null) {
            $shot->setChromePath($binaries['chrome']);
        }

        return $shot;
    }
}
