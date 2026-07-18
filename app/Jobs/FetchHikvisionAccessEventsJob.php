<?php

namespace App\Jobs;

use App\Models\HikvisionSetting;
use App\Models\JobRun;
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

    public function __construct(public int $hikvisionSettingId, public ?string $date = null)
    {
        if (! filled($this->date)) {
            $this->timeout = 180;
        }
    }

    public function handle(?HikvisionService $hikvision = null): void
    {
        $settings = HikvisionSetting::find($this->hikvisionSettingId);

        if ($settings === null) {
            throw new RuntimeException('Hikvision settings no longer exist.');
        }

        $hikvision ??= HikvisionService::forSetting($settings);
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
            if (! $fetchFailed) {
                if (filled($this->date) && $date instanceof Carbon) {
                    SyncHikvisionAttendanceJob::dispatch($date->toDateString(), (int) $settings->company_id);
                } elseif (! filled($this->date)) {
                    SyncHikvisionAttendanceJob::dispatch(null, (int) $settings->company_id);
                }
            }
        }

        if (! $fetchFailed && $result !== null) {
            $settings->markEventsFetchCompleted($result['message']);

            $jobId = $this->job ? $this->job->uuid() : null;
            if ($jobId) {
                JobRun::query()->where('correlation_id', $jobId)->update([
                    'message' => $result['message'],
                    'context' => [
                        'fetched_count' => $result['fetched_count'],
                        'date' => $this->date,
                    ],
                ]);
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        HikvisionSetting::find($this->hikvisionSettingId)?->markEventsFetchFailed(
            $exception->getMessage() !== '' ? $exception->getMessage() : 'Failed to fetch Hikvision access records.',
        );
    }
}
