<?php

namespace App\Jobs;

use App\Support\Attendance\SyncAttendanceRecordsFromHikvision;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncCompanyHikvisionAttendanceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public int $companyId,
        public string $from,
        public string $to,
    ) {}

    public function handle(SyncAttendanceRecordsFromHikvision $attendanceSync): void
    {
        $timezone = (string) config('app.timezone', 'UTC');

        $attendanceSync->syncCompany(
            $this->companyId,
            Carbon::parse($this->from, $timezone),
            Carbon::parse($this->to, $timezone),
        );
    }

    public function failed(Throwable $exception): void
    {
        report($exception);
    }
}
