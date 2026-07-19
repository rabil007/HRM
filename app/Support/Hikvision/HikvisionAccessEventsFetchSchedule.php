<?php

namespace App\Support\Hikvision;

use App\Models\HikvisionSetting;
use App\Support\Settings\ApplicationTimezone;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Support\Collection;
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

            $value = HikvisionSetting::query()
                ->whereNotNull('events_fetch_schedule_at')
                ->where('events_fetch_schedule_at', '!=', '')
                ->orderBy('id')
                ->value('events_fetch_schedule_at');

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

            return HikvisionSetting::query()
                ->where('events_fetch_schedule_enabled', true)
                ->get()
                ->contains(fn (HikvisionSetting $setting): bool => $setting->isConfigured());
        } catch (\Throwable) {
            return false;
        }
    }

    public static function isValidTime(string $value): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    /**
     * @return Collection<int, HikvisionSetting>
     */
    public static function settingsDueForDispatch(): Collection
    {
        $default = (string) config('hikvision.events_fetch_schedule_at', '18:00');

        return HikvisionSetting::query()
            ->where('events_fetch_schedule_enabled', true)
            ->get()
            ->filter(function (HikvisionSetting $setting) use ($default): bool {
                if (! $setting->isConfigured() || $setting->company_id === null) {
                    return false;
                }

                $time = now(CompanyTimezone::forCompany((int) $setting->company_id))->format('H:i');
                $scheduleAt = filled($setting->events_fetch_schedule_at)
                    ? (string) $setting->events_fetch_schedule_at
                    : $default;

                return $scheduleAt === $time;
            })
            ->values();
    }
}
