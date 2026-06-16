<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Support\Attendance\SyncAttendanceRecordsFromHikvision;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessHikvisionWebhookEventJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    public function handle(?SyncAttendanceRecordsFromHikvision $attendanceSync = null): void
    {
        $attendanceSync ??= app(SyncAttendanceRecordsFromHikvision::class);

        $storedEvent = HikvisionAccessEvent::upsertFromWebhook($this->payload);

        if ($storedEvent?->occurrence_time === null) {
            return;
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $eventDay = Carbon::parse($storedEvent->occurrence_time, $timezone);
        $start = $eventDay->copy()->startOfDay();
        $end = $eventDay->copy()->endOfDay();

        $companyIds = Employee::query()
            ->where('status', 'active')
            ->whereNotNull('hikvision_person_id')
            ->distinct()
            ->pluck('company_id');

        foreach ($companyIds as $companyId) {
            $attendanceSync->syncCompany((int) $companyId, $start, $end);
        }
    }
}
