<?php

namespace App\Support\Hikvision;

use App\Models\HikvisionSetting;
use App\Support\Settings\ApplicationTimezone;
use Illuminate\Support\Facades\Schema;

class HikvisionEveningAccessEventsFetchSchedule
{
    public static function dispatchAt(): string
    {
        $default = (string) config('hikvision.events_evening_fetch_schedule_at', '20:00');

        try {
            if (! Schema::hasTable('hikvision_settings')) {
                return $default;
            }

            $value = HikvisionSetting::query()->value('events_evening_fetch_schedule_at');

            if (! is_string($value) || ! HikvisionAccessEventsFetchSchedule::isValidTime($value)) {
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

            return $settings->events_evening_fetch_schedule_enabled
                && $settings->isConfigured();
        } catch (\Throwable) {
            return false;
        }
    }
}
