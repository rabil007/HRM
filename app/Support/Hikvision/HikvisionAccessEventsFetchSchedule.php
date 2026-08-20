<?php

namespace App\Support\Hikvision;

use App\Models\HikvisionReconciliation;
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
     * @return Collection<int, array{setting: HikvisionSetting, target_date: string, origin: HikvisionFetchOrigin}>
     */
    public static function dueReconciliations(): Collection
    {
        $default = (string) config('hikvision.events_fetch_schedule_at', '18:00');
        $lookbackDays = max(1, (int) config('hikvision.reconciliation_lookback_days', 3));

        try {
            if (! Schema::hasTable('hikvision_settings')) {
                return collect();
            }

            $settings = HikvisionSetting::query()
                ->where('events_fetch_schedule_enabled', true)
                ->get();
        } catch (\Throwable) {
            return collect();
        }

        $due = collect();

        foreach ($settings as $setting) {
            if (! $setting->isConfigured() || $setting->company_id === null) {
                continue;
            }

            $companyId = (int) $setting->company_id;
            $companyTimezone = CompanyTimezone::forCompany($companyId);
            $companyNow = now($companyTimezone);
            $currentTime = $companyNow->format('H:i');
            $scheduleAt = filled($setting->events_fetch_schedule_at)
                ? (string) $setting->events_fetch_schedule_at
                : $default;

            if ($currentTime < $scheduleAt) {
                continue;
            }

            [$scheduleHour, $scheduleMinute] = explode(':', $scheduleAt);
            $cycleCutoff = $companyNow->copy()->setTime((int) $scheduleHour, (int) $scheduleMinute, 0);

            $dueForCompany = collect();

            for ($daysAgo = $lookbackDays; $daysAgo >= 1; $daysAgo--) {
                $targetDate = $companyNow->copy()->subDays($daysAgo)->toDateString();

                if (HikvisionReconciliation::shouldDispatchReconciliation($companyId, $targetDate, $cycleCutoff)) {
                    $isUnprocessed = ! HikvisionReconciliation::wasSuccessfullyProcessed($companyId, $targetDate);
                    $origin = $daysAgo === 1
                        ? HikvisionFetchOrigin::ScheduledReconciliation
                        : HikvisionFetchOrigin::CatchUp;

                    $dueForCompany->push([
                        'setting' => $setting,
                        'target_date' => $targetDate,
                        'origin' => $origin,
                        'is_unprocessed' => $isUnprocessed,
                    ]);
                }
            }

            $unprocessed = $dueForCompany->filter(fn (array $item): bool => $item['is_unprocessed']);
            $stabilization = $dueForCompany->reject(fn (array $item): bool => $item['is_unprocessed']);

            foreach ($unprocessed->concat($stabilization) as $item) {
                unset($item['is_unprocessed']);
                $due->push($item);
            }
        }

        return $due->values();
    }

    /**
     * @return Collection<int, HikvisionSetting>
     */
    public static function settingsDueForDispatch(): Collection
    {
        return self::dueReconciliations()
            ->pluck('setting')
            ->unique('id')
            ->values();
    }
}
