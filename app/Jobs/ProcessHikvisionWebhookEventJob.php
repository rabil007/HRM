<?php

namespace App\Jobs;

use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionSetting;
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
    public function __construct(public array $payload, public int $hikvisionSettingId) {}

    public function handle(?SyncAttendanceRecordsFromHikvision $attendanceSync = null): void
    {
        $attendanceSync ??= app(SyncAttendanceRecordsFromHikvision::class);

        $settings = HikvisionSetting::find($this->hikvisionSettingId);

        if ($settings === null) {
            return;
        }

        $storedEvent = HikvisionAccessEvent::upsertFromWebhook($this->payload, (int) $settings->company_id);

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

        $synced = $attendanceSync->syncCompany((int) $settings->company_id, $start, $end);

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
