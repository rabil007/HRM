<?php

namespace App\Jobs;

use App\Models\HikvisionSetting;
use App\Support\Hikvision\DispatchHikvisionWebhookTriggeredFetch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessHikvisionWebhookTrailingFetchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public int $hikvisionSettingId,
        public string $targetDate,
    ) {}

    public function handle(?DispatchHikvisionWebhookTriggeredFetch $dispatchFetch = null): void
    {
        $dispatchFetch ??= app(DispatchHikvisionWebhookTriggeredFetch::class);

        $dispatchFetch->releaseTrailingSchedule($this->hikvisionSettingId, $this->targetDate);

        $settings = HikvisionSetting::find($this->hikvisionSettingId);

        if ($settings === null) {
            Log::warning('Hikvision webhook trailing fetch skipped because settings no longer exist.', [
                'hikvision_setting_id' => $this->hikvisionSettingId,
            ]);

            return;
        }

        if ($settings->company_id === null || ! $settings->isConfigured()) {
            return;
        }

        $settings->resolveStaleEventsFetch(5);
        $settings->refresh();

        if ($settings->isEventsFetchProcessing()) {
            $dispatchFetch->scheduleTrailingFetch($settings->id, $this->targetDate);

            return;
        }

        if (! $dispatchFetch->consumePendingTrailingFetch($this->hikvisionSettingId, $this->targetDate)) {
            return;
        }

        $dispatchFetch->handle($settings, $this->targetDate);
    }
}
