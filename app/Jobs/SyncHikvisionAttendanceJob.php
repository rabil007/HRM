<?php

namespace App\Jobs;

use App\Models\JobRun;
use App\Services\HikvisionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class SyncHikvisionAttendanceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public ?string $date = null) {}

    public function handle(HikvisionService $hikvision): void
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $synced = 0;

        if (filled($this->date)) {
            $day = Carbon::parse($this->date, $timezone)->startOfDay();
            $synced += $hikvision->syncAttendanceForDay($day);

            if ($day->isToday()) {
                $synced += $hikvision->syncAttendanceForDay($day->copy()->subDay());
            }
        } else {
            $synced += $hikvision->syncAttendanceForScheduledDays();
        }

        $jobId = $this->job ? $this->job->uuid() : null;
        if ($jobId) {
            JobRun::query()->where('correlation_id', $jobId)->update([
                'message' => "Successfully synchronized {$synced} attendance record(s).",
                'context' => [
                    'synced_records_count' => $synced,
                    'date' => $this->date,
                ],
            ]);
        }
    }
}
