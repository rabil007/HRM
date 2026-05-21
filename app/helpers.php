<?php

use App\Services\Settings\SettingService;

if (! function_exists('setting')) {
    function setting(string $key, ?string $default = null): ?string
    {
        return app(SettingService::class)->get($key, $default);
    }
}

if (! function_exists('settings')) {
    /** @return array<string, string|null> */
    function settings(): array
    {
        return app(SettingService::class)->all();
    }
}

if (! function_exists('app_name')) {
    function app_name(): string
    {
        return app(SettingService::class)->appName();
    }
}
