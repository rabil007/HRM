<?php

namespace App\Jobs;

use App\Models\HikvisionAccessEvent;
use App\Models\HikvisionSetting;
use App\Models\JobRun;
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
    public function __construct(public array $payload, public int $hikvisionSettingId) {}

    public function handle(?SyncAttendanceRecordsFromHikvision $attendanceSync = null): void
    {
        $attendanceSync ??= app(SyncAttendanceRecordsFromHikvision::class);

        $settings = HikvisionSetting::find($this->hikvisionSettingId);

        if ($settings === null) {
            Log::warning('Hikvision webhook job skipped because settings no longer exist.', [
                'hikvision_setting_id' => $this->hikvisionSettingId,
            ]);

            return;
        }

        if ($settings->company_id === null) {
            Log::warning('Hikvision webhook job skipped because settings have no company ownership.', [
                'hikvision_setting_id' => $settings->id,
            ]);

            return;
        }

        $companyId = (int) $settings->company_id;
        $storedEvent = HikvisionAccessEvent::upsertFromWebhook($this->payload, $companyId);

        // #region agent log
        @file_put_contents(base_path('.cursor/debug-688778.log'), json_encode(['sessionId' => '688778', 'runId' => 'post-fix', 'hypothesisId' => 'W4', 'location' => 'ProcessHikvisionWebhookEventJob.php:handle', 'message' => 'webhook upsert result', 'data' => ['company_id' => $companyId, 'stored' => $storedEvent !== null, 'event_id' => $storedEvent?->id, 'person_name' => $storedEvent?->person_name, 'occurrence_time' => optional($storedEvent?->occurrence_time)->toIso8601String(), 'event_source' => $storedEvent?->event_source, 'system_id' => $storedEvent?->system_id], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND | LOCK_EX);
        // #endregion

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

        $synced = $attendanceSync->syncCompany($companyId, $start, $end);

        $jobId = $this->job ? $this->job->uuid() : null;
        if ($jobId) {
            JobRun::query()->where('correlation_id', $jobId)->update([
                'message' => "Processed webhook scan event for {$storedEvent->person_name}. Synced {$synced} attendance record(s) for {$eventDay->toDateString()}.",
                'context' => [
                    'person_name' => $storedEvent->person_name,
                    'synced_records_count' => $synced,
                    'event_date' => $eventDay->toDateString(),
                    'company_id' => $companyId,
                ],
            ]);
        }
    }
}
