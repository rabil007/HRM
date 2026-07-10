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
        // #region agent log
        $startedAt = microtime(true);
        @file_put_contents(base_path('.cursor/debug-ed6c6d.log'), json_encode([
            'sessionId' => 'ed6c6d',
            'runId' => 'pre-fix',
            'hypothesisId' => 'A,B,C',
            'location' => 'SyncHikvisionAttendanceJob.php:handle:start',
            'message' => 'SyncHikvisionAttendanceJob started',
            'data' => [
                'date' => $this->date,
                'jobTimeout' => $this->timeout,
                'jobTries' => $this->tries,
                'dbRetryAfter' => (int) config('queue.connections.database.retry_after'),
                'retryAfterLessThanTimeout' => (int) config('queue.connections.database.retry_after') < $this->timeout,
                'queueConnection' => (string) config('queue.default'),
            ],
            'timestamp' => (int) round(microtime(true) * 1000),
        ], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
        // #endregion

        $timezone = (string) config('app.timezone', 'UTC');
        $synced = 0;

        try {
            if (filled($this->date)) {
                $day = Carbon::parse($this->date, $timezone)->startOfDay();
                $synced += $hikvision->syncAttendanceForDay($day);

                if ($day->isToday()) {
                    $synced += $hikvision->syncAttendanceForDay($day->copy()->subDay());
                }
            } else {
                $synced += $hikvision->syncAttendanceForYesterday();
            }
        } catch (\Throwable $exception) {
            // #region agent log
            @file_put_contents(base_path('.cursor/debug-ed6c6d.log'), json_encode([
                'sessionId' => 'ed6c6d',
                'runId' => 'pre-fix',
                'hypothesisId' => 'D',
                'location' => 'SyncHikvisionAttendanceJob.php:handle:error',
                'message' => 'SyncHikvisionAttendanceJob failed',
                'data' => [
                    'date' => $this->date,
                    'exceptionClass' => $exception::class,
                    'exceptionMessage' => $exception->getMessage(),
                    'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
                ],
                'timestamp' => (int) round(microtime(true) * 1000),
            ], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
            // #endregion

            throw $exception;
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

        // #region agent log
        @file_put_contents(base_path('.cursor/debug-ed6c6d.log'), json_encode([
            'sessionId' => 'ed6c6d',
            'runId' => 'pre-fix',
            'hypothesisId' => 'A,D',
            'location' => 'SyncHikvisionAttendanceJob.php:handle:end',
            'message' => 'SyncHikvisionAttendanceJob finished',
            'data' => [
                'date' => $this->date,
                'synced' => $synced,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
                'dbRetryAfter' => (int) config('queue.connections.database.retry_after'),
                'jobTimeout' => $this->timeout,
            ],
            'timestamp' => (int) round(microtime(true) * 1000),
        ], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
        // #endregion
    }
}
