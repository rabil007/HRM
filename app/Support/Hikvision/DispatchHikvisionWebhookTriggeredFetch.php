<?php

namespace App\Support\Hikvision;

use App\Jobs\FetchHikvisionAccessEventsJob;
use App\Jobs\ProcessHikvisionWebhookTrailingFetchJob;
use App\Models\HikvisionSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches an authoritative same-day access-events fetch from a webhook notification.
 *
 * Webhook JSON is never trusted. Debounce and trailing fetch are webhook-specific
 * and do not couple FetchHikvisionAccessEventsJob uniqueness for other origins.
 */
final class DispatchHikvisionWebhookTriggeredFetch
{
    public const RESULT_DISPATCHED = 'dispatched';

    public const RESULT_DEBOUNCED = 'debounced';

    public const RESULT_ALREADY_PROCESSING = 'already_processing';

    public function handle(HikvisionSetting $settings, string $targetDate): string
    {
        $settings->resolveStaleEventsFetch(5);
        $settings->refresh();

        if ($settings->isEventsFetchProcessing()) {
            $this->scheduleTrailingFetch($settings->id, $targetDate);

            return self::RESULT_ALREADY_PROCESSING;
        }

        if (! $this->acquireWebhookDebounce($settings->id, $targetDate)) {
            $this->scheduleTrailingFetch($settings->id, $targetDate);

            return self::RESULT_DEBOUNCED;
        }

        $settings->beginEventsFetch();

        FetchHikvisionAccessEventsJob::dispatch(
            $settings->id,
            $targetDate,
            HikvisionFetchOrigin::WebhookTrigger,
        );

        return self::RESULT_DISPATCHED;
    }

    public static function debounceCacheKey(int $hikvisionSettingId, string $targetDate): string
    {
        return "hikvision:webhook-triggered-fetch:{$hikvisionSettingId}:{$targetDate}";
    }

    public static function pendingCacheKey(int $hikvisionSettingId, string $targetDate): string
    {
        return "hikvision:webhook-triggered-fetch-pending:{$hikvisionSettingId}:{$targetDate}";
    }

    public static function trailingCacheKey(int $hikvisionSettingId, string $targetDate): string
    {
        return "hikvision:webhook-triggered-fetch-trailing:{$hikvisionSettingId}:{$targetDate}";
    }

    public static function debounceSeconds(): int
    {
        return max(30, min(120, (int) config('hikvision.webhook_trigger_debounce_seconds', 60)));
    }

    public function consumePendingTrailingFetch(int $hikvisionSettingId, string $targetDate): bool
    {
        try {
            $key = self::pendingCacheKey($hikvisionSettingId, $targetDate);

            if (! Cache::pull($key)) {
                return false;
            }

            return true;
        } catch (Throwable $exception) {
            Log::warning('Hikvision webhook trailing-fetch pending flag unavailable.', [
                'hikvision_setting_id' => $hikvisionSettingId,
                'target_date' => $targetDate,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    public function releaseTrailingSchedule(int $hikvisionSettingId, string $targetDate): void
    {
        try {
            Cache::forget(self::trailingCacheKey($hikvisionSettingId, $targetDate));
        } catch (Throwable $exception) {
            Log::warning('Hikvision webhook trailing-fetch schedule flag unavailable.', [
                'hikvision_setting_id' => $hikvisionSettingId,
                'target_date' => $targetDate,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function scheduleTrailingFetch(int $hikvisionSettingId, string $targetDate): void
    {
        try {
            Cache::put(
                self::pendingCacheKey($hikvisionSettingId, $targetDate),
                true,
                now()->addSeconds(self::debounceSeconds() + 30),
            );

            if (! Cache::add(
                self::trailingCacheKey($hikvisionSettingId, $targetDate),
                true,
                now()->addSeconds(self::debounceSeconds()),
            )) {
                return;
            }

            ProcessHikvisionWebhookTrailingFetchJob::dispatch($hikvisionSettingId, $targetDate)
                ->delay(now()->addSeconds(self::debounceSeconds()));
        } catch (Throwable $exception) {
            Log::warning('Hikvision webhook trailing fetch unavailable; continuing without coalesced replay.', [
                'hikvision_setting_id' => $hikvisionSettingId,
                'target_date' => $targetDate,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function acquireWebhookDebounce(int $hikvisionSettingId, string $targetDate): bool
    {
        try {
            return Cache::add(
                self::debounceCacheKey($hikvisionSettingId, $targetDate),
                true,
                now()->addSeconds(self::debounceSeconds()),
            );
        } catch (Throwable $exception) {
            Log::warning('Hikvision webhook fetch debounce unavailable; continuing without coalescing.', [
                'hikvision_setting_id' => $hikvisionSettingId,
                'target_date' => $targetDate,
                'error' => $exception->getMessage(),
            ]);

            return true;
        }
    }
}
