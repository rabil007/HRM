<?php

namespace App\Support\Settings;

use App\Services\Settings\SettingService;

class ApplicationTimezone
{
    public static function identifier(): string
    {
        $settings = app(SettingService::class);

        if ($settings->isReady()) {
            $timezone = $settings->get(SettingKey::Timezone);

            if (is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)) {
                return $timezone;
            }
        }

        $fallback = (string) config('app.timezone', 'UTC');

        return in_array($fallback, timezone_identifiers_list(), true) ? $fallback : 'UTC';
    }
}
