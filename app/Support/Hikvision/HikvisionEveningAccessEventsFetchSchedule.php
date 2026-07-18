<?php

namespace App\Support\Hikvision;

use App\Models\HikvisionSetting;
use App\Support\Settings\ApplicationTimezone;
use Illuminate\Support\Collection;
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

            $value = HikvisionSetting::query()
                ->whereNotNull('events_evening_fetch_schedule_at')
                ->where('events_evening_fetch_schedule_at', '!=', '')
                ->orderBy('id')
                ->value('events_evening_fetch_schedule_at');

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

            return HikvisionSetting::query()
                ->where('events_evening_fetch_schedule_enabled', true)
                ->get()
                ->contains(fn (HikvisionSetting $setting): bool => $setting->isConfigured());
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return Collection<int, HikvisionSetting>
     */
    public static function settingsDueForDispatch(): Collection
    {
        $time = now(self::timezone())->format('H:i');
        $default = (string) config('hikvision.events_evening_fetch_schedule_at', '20:00');

        return HikvisionSetting::query()
            ->where('events_evening_fetch_schedule_enabled', true)
            ->get()
            ->filter(function (HikvisionSetting $setting) use ($time, $default): bool {
                if (! $setting->isConfigured()) {
                    return false;
                }

                $scheduleAt = filled($setting->events_evening_fetch_schedule_at)
                    ? (string) $setting->events_evening_fetch_schedule_at
                    : $default;

                return $scheduleAt === $time;
            })
            ->values();
    }
}
