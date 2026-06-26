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

    public function __construct(public ?string $date = null)
    {
        if (! filled($this->date)) {
            $this->timeout = 300;
        }
    }

    public function handle(HikvisionService $hikvision): void
    {
        $settings = HikvisionSetting::current();
        $settings->markEventsFetchRunning();

        $timezone = (string) config('app.timezone', 'UTC');
        $date = filled($this->date)
            ? Carbon::parse($this->date, $timezone)->startOfDay()
            : null;

        $result = null;
        $fetchFailed = false;

        try {
            $result = filled($this->date)
                ? $hikvision->fetchAccessEvents($date)
                : $hikvision->fetchScheduledAccessEvents();
        } catch (RuntimeException $exception) {
            $fetchFailed = true;
            $settings->markEventsFetchFailed($exception->getMessage());
        } finally {
            if (filled($this->date) && $date instanceof Carbon) {
                $hikvision->syncAttendanceForDay($date);

                if ($date->isToday()) {
                    $hikvision->syncAttendanceForDay($date->copy()->subDay());
                }
            } elseif (! filled($this->date)) {
                $hikvision->syncAttendanceForScheduledDays();
            }
        }

        if (! $fetchFailed && $result !== null) {
            $settings->markEventsFetchCompleted($result['message']);
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
