<?php

namespace App\Support\Hikvision;

use App\Jobs\FetchHikvisionAccessEventsJob;
use App\Models\HikvisionSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatches an authoritative same-day access-events fetch from a webhook notification.
 *
 * Webhook JSON is never trusted. Debounce is webhook-specific (cache) and does not
 * couple FetchHikvisionAccessEventsJob uniqueness for manual/scheduled origins.
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
            return self::RESULT_ALREADY_PROCESSING;
        }

        if (! $this->acquireWebhookDebounce($settings->id, $targetDate)) {
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

    public static function debounceSeconds(): int
    {
        return max(30, min(120, (int) config('hikvision.webhook_trigger_debounce_seconds', 60)));
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

            // Cache failure must not block the authoritative fetch path.
            return true;
        }
    }
}
