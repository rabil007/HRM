<?php

namespace App\Jobs;

use App\Models\JobRun;
use App\Support\Attendance\DispatchHikvisionAttendanceSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class SyncHikvisionAttendanceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public ?string $date = null, public ?int $companyId = null) {}

    public function handle(DispatchHikvisionAttendanceSync $dispatch): void
    {
        $timezone = (string) config('app.timezone', 'UTC');
        $dispatched = 0;

        if (filled($this->date)) {
            $day = Carbon::parse($this->date, $timezone)->startOfDay();
            $dispatched += $dispatch->dispatchForWindow(
                $day->copy()->startOfDay(),
                $day->copy()->endOfDay(),
                $this->companyId,
            );

            if ($day->isToday()) {
                $yesterday = $day->copy()->subDay();
                $dispatched += $dispatch->dispatchForWindow(
                    $yesterday->copy()->startOfDay(),
                    $yesterday->copy()->endOfDay(),
                    $this->companyId,
                );
            }
        } else {
            $yesterday = now($timezone)->copy()->subDay()->startOfDay();
            $dispatched += $dispatch->dispatchForWindow(
                $yesterday->copy()->startOfDay(),
                $yesterday->copy()->endOfDay(),
                $this->companyId,
            );
        }

        $jobId = $this->job ? $this->job->uuid() : null;
        if ($jobId) {
            JobRun::query()->where('correlation_id', $jobId)->update([
                'message' => "Dispatched {$dispatched} company attendance sync job(s).",
                'context' => [
                    'dispatched_jobs_count' => $dispatched,
                    'date' => $this->date,
                ],
            ]);
        }
    }
}
