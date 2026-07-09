<?php

use App\Support\BulkDocuments\EnsuresBrowsershotChromePermissions;

test('ensures browsershot chrome permissions marks bundled chrome files executable', function () {
    $cacheDir = storage_path('app/testing-puppeteer-perms-'.uniqid());
    $chromeDir = $cacheDir.'/chrome-headless-shell/linux-150.0.7871.24/chrome-headless-shell-linux64';
    mkdir($chromeDir, 0755, true);

    $chrome = $chromeDir.'/chrome-headless-shell';
    $library = $chromeDir.'/libvk_swiftshader.so';

    file_put_contents($chrome, "#!/bin/sh\necho chrome\n");
    file_put_contents($library, 'lib');
    chmod($chrome, 0644);
    chmod($library, 0644);

    EnsuresBrowsershotChromePermissions::apply($cacheDir);

    expect(decoct(fileperms($chrome) & 0777))->toBe('755')
        ->and(decoct(fileperms($library) & 0777))->toBe('755')
        ->and(is_dir($cacheDir.'/home'))->toBeTrue();
});
