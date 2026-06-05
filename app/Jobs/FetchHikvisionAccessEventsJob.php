<?php

namespace App\Jobs;

use App\Models\HikvisionSetting;
use App\Services\HikvisionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class FetchHikvisionAccessEventsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function handle(HikvisionService $hikvision): void
    {
        $settings = HikvisionSetting::current();
        $settings->markEventsFetchRunning();

        try {
            $result = $hikvision->fetchAccessEvents();
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
