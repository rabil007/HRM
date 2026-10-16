<?php

use App\Models\AppSetting;
use App\Services\Settings\SettingService;
use App\Support\Settings\PlatformDateFormatter;
use App\Support\Settings\SettingKey;
use Illuminate\Support\Facades\Cache;

test('setting updates are visible on the same scoped service after cache clear', function () {
    $settings = app(SettingService::class);
    $settings->set(SettingKey::DateFormat, 'd/m/Y');

    expect(PlatformDateFormatter::make()->format())->toBe('d/m/Y');

    $settings->set(SettingKey::DateFormat, 'M d, Y');

    expect(PlatformDateFormatter::make()->format())->toBe('M d, Y')
        ->and($settings->get(SettingKey::DateFormat))->toBe('M d, Y');
});

test('settings stay fresh across simulated queue job boundaries', function () {
    app(SettingService::class)->set(SettingKey::DateFormat, 'd/m/Y');
    expect(PlatformDateFormatter::make()->date('2026-05-06'))->toBe('06/05/2026');

    // Another process updates the DB and forgets the shared cache key.
    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::DateFormat],
        ['value' => 'm/d/Y', 'type' => 'string'],
    );
    Cache::forget('app.settings.all');

    // Laravel flushes scoped instances between queue jobs.
    app()->forgetInstance(SettingService::class);
    app()->forgetInstance(PlatformDateFormatter::class);

    expect(PlatformDateFormatter::make()->format())->toBe('m/d/Y')
        ->and(PlatformDateFormatter::make()->date('2026-05-06'))->toBe('05/06/2026');
});

test('external cache invalidation is visible without restarting the worker process', function () {
    $settings = app(SettingService::class);
    $settings->set(SettingKey::DateFormat, 'Y-m-d');
    expect($settings->get(SettingKey::DateFormat))->toBe('Y-m-d');

    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::DateFormat],
        ['value' => 'd-m-Y', 'type' => 'string'],
    );
    Cache::forget('app.settings.all');

    // Same long-lived worker / Octane process — must re-read Laravel Cache.
    expect($settings->get(SettingKey::DateFormat))->toBe('d-m-Y')
        ->and(PlatformDateFormatter::make()->date('2026-05-06'))->toBe('06-05-2026');
});
