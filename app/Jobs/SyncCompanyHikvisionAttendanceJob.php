<?php

namespace App\Jobs;

use App\Models\HikvisionReconciliation;
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

        $fromDate = Carbon::parse($this->from, $timezone);
        $toDate = Carbon::parse($this->to, $timezone);

        $synced = $attendanceSync->syncCompany(
            $this->companyId,
            $fromDate,
            $toDate,
        );

        if ($fromDate->toDateString() === $toDate->toDateString()) {
            HikvisionReconciliation::recordAttendanceSynced($this->companyId, $fromDate->toDateString(), $synced);
        }
    }

    public function failed(Throwable $exception): void
    {
        report($exception);

        $timezone = (string) config('app.timezone', 'UTC');
        $fromDate = Carbon::parse($this->from, $timezone)->toDateString();
        $toDate = Carbon::parse($this->to, $timezone)->toDateString();

        if ($fromDate === $toDate) {
            HikvisionReconciliation::markFailed($this->companyId, $fromDate, $exception->getMessage());
        }
    }
}
