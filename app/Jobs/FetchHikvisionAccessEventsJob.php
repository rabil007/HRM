<?php

namespace App\Jobs;

use App\Models\HikvisionSetting;
use App\Services\HikvisionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

class FetchHikvisionAccessEventsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public ?string $date = null) {}

    public function handle(HikvisionService $hikvision): void
    {
        $settings = HikvisionSetting::current();
        $settings->markEventsFetchRunning();

        try {
            $timezone = (string) config('app.timezone', 'UTC');
            $date = filled($this->date)
                ? Carbon::parse($this->date, $timezone)->startOfDay()
                : null;

            // #region agent log
            @file_put_contents(base_path('.cursor/debug-e1f1d0.log'), json_encode([
                'sessionId' => 'e1f1d0',
                'timestamp' => (int) round(microtime(true) * 1000),
                'location' => 'FetchHikvisionAccessEventsJob::handle',
                'message' => 'job started',
                'data' => [
                    'dateParam' => $this->date,
                    'isScheduledFetch' => ! filled($this->date),
                    'timezone' => $timezone,
                    'now' => now($timezone)->toIso8601String(),
                ],
                'hypothesisId' => 'B',
            ], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
            // #endregion

            $result = filled($this->date)
                ? $hikvision->fetchAccessEvents($date)
                : $hikvision->fetchScheduledAccessEvents();

            $settings->markEventsFetchCompleted($result['message']);
        } catch (RuntimeException $exception) {
            $settings->markEventsFetchFailed($exception->getMessage());
        }
    }

    public function failed(Throwable $exception): void
    {
        HikvisionSetting::current()->markEventsFetchFailed(
            $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'Failed to fetch Hikvision access records.',
        );
    }
}
