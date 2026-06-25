<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\HikvisionAccessEvent;
use App\Support\Attendance\SyncAttendanceRecordsFromHikvision;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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
            Log::info('hikvision.webhook_event_skipped', [
                'reason' => 'missing_occurrence_time',
            ]);

            return;
        }

        $timezone = (string) config('app.timezone', 'UTC');
        $eventDay = Carbon::parse($storedEvent->occurrence_time, $timezone);
        $start = $eventDay->copy()->startOfDay();
        $end = $eventDay->copy()->endOfDay();

        Log::info('hikvision.webhook_event_stored', [
            'event_id' => $storedEvent->id,
            'system_id' => $storedEvent->system_id,
            'person_name' => $storedEvent->person_name,
            'person_hikvision_id' => $storedEvent->person_hikvision_id,
            'attendance_status' => $storedEvent->attendance_status,
            'occurrence_time' => $storedEvent->occurrence_time?->toIso8601String(),
            'sync_range_start' => $start->toIso8601String(),
            'sync_range_end' => $end->toIso8601String(),
        ]);

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
