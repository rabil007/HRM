<?php

use App\Support\BulkDocuments\ConfiguresBrowsershotEnvironment;

test('configures browsershot environment uses default puppeteer cache directory', function () {
    config()->set('services.browsershot.puppeteer_cache_dir', null);

    $cacheDir = ConfiguresBrowsershotEnvironment::apply();

    expect($cacheDir)->toBe(storage_path('app/puppeteer'))
        ->and(is_dir($cacheDir))->toBeTrue()
        ->and(getenv('PUPPETEER_CACHE_DIR'))->toBe($cacheDir)
        ->and($_ENV['PUPPETEER_CACHE_DIR'])->toBe($cacheDir)
        ->and($_SERVER['PUPPETEER_CACHE_DIR'])->toBe($cacheDir);
});

test('configures browsershot environment respects configured puppeteer cache directory', function () {
    $customDir = storage_path('app/testing-puppeteer-cache');

    config()->set('services.browsershot.puppeteer_cache_dir', $customDir);

    $cacheDir = ConfiguresBrowsershotEnvironment::apply();

    expect($cacheDir)->toBe($customDir)
        ->and(is_dir($cacheDir))->toBeTrue()
        ->and(getenv('PUPPETEER_CACHE_DIR'))->toBe($customDir);
});

test('browsershot install command is registered', function () {
    $this->artisan('browsershot:install --help')
        ->assertSuccessful();
});
