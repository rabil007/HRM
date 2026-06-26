<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Models\JobRun;
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
            $jobId = $this->job ? $this->job->uuid() : null;
            if ($jobId) {
                JobRun::query()->where('correlation_id', $jobId)->update([
                    'message' => 'Ignored webhook event: occurrence time is missing.',
                ]);
            }

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

        $synced = 0;

        foreach ($companyIds as $companyId) {
            $synced += $attendanceSync->syncCompany((int) $companyId, $start, $end);
        }

        $jobId = $this->job ? $this->job->uuid() : null;
        if ($jobId) {
            JobRun::query()->where('correlation_id', $jobId)->update([
                'message' => "Processed webhook scan event for {$storedEvent->person_name}. Synced {$synced} attendance record(s) for {$eventDay->toDateString()}.",
                'context' => [
                    'person_name' => $storedEvent->person_name,
                    'synced_records_count' => $synced,
                    'event_date' => $eventDay->toDateString(),
                ],
            ]);
        }
    }
}
