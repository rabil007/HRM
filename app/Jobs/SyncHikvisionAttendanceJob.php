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
        $jobStartedAt = microtime(true);

        // #region agent log
        file_put_contents(
            '/Users/mohammedrabil/Herd/OMS-HRM/.cursor/debug-906348.log',
            json_encode([
                'sessionId' => '906348',
                'hypothesisId' => 'B',
                'location' => 'SyncHikvisionAttendanceJob.php:handle',
                'message' => 'job started',
                'data' => ['date' => $this->date],
                'timestamp' => (int) round(microtime(true) * 1000),
                'runId' => 'pre-fix',
            ], JSON_THROW_ON_ERROR)."\n",
            FILE_APPEND
        );
        // #endregion

        $timezone = (string) config('app.timezone', 'UTC');
        $synced = 0;

        if (filled($this->date)) {
            $day = Carbon::parse($this->date, $timezone)->startOfDay();
            $synced += $hikvision->syncAttendanceForDay($day);

            if ($day->isToday()) {
                $synced += $hikvision->syncAttendanceForDay($day->copy()->subDay());
            }
        } else {
            $synced += $hikvision->syncAttendanceForYesterday();
        }

        // #region agent log
        file_put_contents(
            '/Users/mohammedrabil/Herd/OMS-HRM/.cursor/debug-906348.log',
            json_encode([
                'sessionId' => '906348',
                'hypothesisId' => 'B',
                'location' => 'SyncHikvisionAttendanceJob.php:handle',
                'message' => 'job finished',
                'data' => [
                    'date' => $this->date,
                    'synced' => $synced,
                    'elapsed_ms' => (int) round((microtime(true) - $jobStartedAt) * 1000),
                ],
                'timestamp' => (int) round(microtime(true) * 1000),
                'runId' => 'pre-fix',
            ], JSON_THROW_ON_ERROR)."\n",
            FILE_APPEND
        );
        // #endregion

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
