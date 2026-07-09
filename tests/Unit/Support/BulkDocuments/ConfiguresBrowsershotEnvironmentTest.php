<?php

use App\Support\BulkDocuments\ConfiguresBrowsershotEnvironment;
use App\Support\BulkDocuments\ResolvesBrowsershotBinaries;

test('configures browsershot environment uses default puppeteer cache directory', function () {
    config()->set('services.browsershot.puppeteer_cache_dir', null);

    $cacheDir = ConfiguresBrowsershotEnvironment::apply();

    expect($cacheDir)->toBe(storage_path('app/puppeteer'))
        ->and(is_dir($cacheDir))->toBeTrue()
        ->and(is_dir($cacheDir.'/home'))->toBeTrue()
        ->and(getenv('PUPPETEER_CACHE_DIR'))->toBe($cacheDir)
        ->and(getenv('HOME'))->toBe($cacheDir.'/home')
        ->and($_ENV['PUPPETEER_CACHE_DIR'])->toBe($cacheDir)
        ->and($_SERVER['PUPPETEER_CACHE_DIR'])->toBe($cacheDir)
        ->and($_ENV['HOME'])->toBe($cacheDir.'/home')
        ->and($_SERVER['HOME'])->toBe($cacheDir.'/home');
});

test('configures browsershot environment respects configured puppeteer cache directory', function () {
    $customDir = storage_path('app/testing-puppeteer-cache-'.uniqid());
    $chromeDir = $customDir.'/chrome-headless-shell/linux-1.0.0/chrome-headless-shell-linux64';
    mkdir($chromeDir, 0755, true);
    $chromePath = $chromeDir.'/chrome-headless-shell';
    file_put_contents($chromePath, "#!/bin/sh\necho ok\n");
    chmod($chromePath, 0755);

    config()->set('services.browsershot.puppeteer_cache_dir', $customDir);

    $cacheDir = ConfiguresBrowsershotEnvironment::apply();

    expect($cacheDir)->toBe($customDir)
        ->and(is_dir($cacheDir))->toBeTrue()
        ->and(is_dir($customDir.'/home'))->toBeTrue()
        ->and(getenv('PUPPETEER_CACHE_DIR'))->toBe($customDir)
        ->and(getenv('HOME'))->toBe($customDir.'/home');

    @unlink($chromePath);
    @rmdir($chromeDir);
    @rmdir(dirname($chromeDir));
    @rmdir(dirname(dirname($chromeDir)));
    @rmdir($customDir.'/home');
    @rmdir($customDir);
});

test('configures browsershot environment falls back when configured cache has no chrome', function () {
    $emptyCache = storage_path('app/empty-puppeteer-cache-'.uniqid());
    mkdir($emptyCache, 0755, true);

    config()->set('services.browsershot.puppeteer_cache_dir', $emptyCache);

    $resolved = ConfiguresBrowsershotEnvironment::resolveCacheDir();

    expect($resolved)->toBe(storage_path('app/puppeteer'));

    @rmdir($emptyCache);
});

test('resolves chrome from default cache when configured cache is empty', function () {
    $emptyCache = storage_path('app/empty-puppeteer-cache-'.uniqid());
    mkdir($emptyCache, 0755, true);

    config()->set('services.browsershot.puppeteer_cache_dir', $emptyCache);
    config()->set('services.browsershot.chrome_path', null);

    $chrome = ResolvesBrowsershotBinaries::chromePath();

    expect($chrome)->not->toBeNull()
        ->and(str_contains((string) $chrome, storage_path('app/puppeteer')))->toBeTrue()
        ->and(is_executable((string) $chrome))->toBeTrue();

    @rmdir($emptyCache);
});

test('browsershot install command is registered', function () {
    $this->artisan('browsershot:install --help')
        ->assertSuccessful();
});
