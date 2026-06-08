<?php

namespace App\Support\Hikvision;

use App\Models\HikvisionSetting;
use App\Support\Settings\ApplicationTimezone;
use Illuminate\Support\Facades\Schema;

class HikvisionAccessEventsFetchSchedule
{
    public static function dispatchAt(): string
    {
        $default = (string) config('hikvision.events_fetch_schedule_at', '18:00');

        try {
            if (! Schema::hasTable('hikvision_settings')) {
                return $default;
            }

            $value = HikvisionSetting::query()->value('events_fetch_schedule_at');

            if (! is_string($value) || ! self::isValidTime($value)) {
                return $default;
            }

            return $value;
        } catch (\Throwable) {
            return $default;
        }
    }

    public static function timezone(): string
    {
        return ApplicationTimezone::identifier();
    }

    public static function isEnabled(): bool
    {
        try {
            if (! Schema::hasTable('hikvision_settings')) {
                return false;
            }

            $settings = HikvisionSetting::current();

            return $settings->events_fetch_schedule_enabled
                && $settings->isConfigured();
        } catch (\Throwable) {
            return false;
        }
    }

    public static function isValidTime(string $value): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }
}
