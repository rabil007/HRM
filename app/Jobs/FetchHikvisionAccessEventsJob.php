<?php

namespace App\Jobs;

use App\Models\HikvisionSetting;
use App\Models\JobRun;
use App\Services\HikvisionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class FetchHikvisionAccessEventsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public int $hikvisionSettingId, public ?string $date = null)
    {
        if (! filled($this->date)) {
            $this->timeout = 180;
        }
    }

    public function handle(?HikvisionService $hikvision = null): void
    {
        $settings = HikvisionSetting::find($this->hikvisionSettingId);

        if ($settings === null) {
            Log::warning('Hikvision fetch skipped because settings no longer exist.', [
                'hikvision_setting_id' => $this->hikvisionSettingId,
            ]);

            return;
        }

        // #region agent log
        @file_put_contents(base_path('.cursor/debug-688778.log'), json_encode(['sessionId' => '688778', 'runId' => 'pre-fix', 'hypothesisId' => 'A', 'location' => 'FetchHikvisionAccessEventsJob.php:handle', 'message' => 'job start settings state', 'data' => ['setting_id' => $settings->id, 'company_id' => $settings->company_id, 'enabled' => (bool) $settings->enabled, 'has_api_host' => filled($settings->api_host), 'has_api_key' => filled($settings->api_key), 'has_api_secret' => filled($settings->api_secret), 'is_configured' => $settings->isConfigured(), 'date' => $this->date, 'env' => app()->environment()], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND | LOCK_EX);
        // #endregion

        if ($settings->company_id === null) {
            Log::warning('Hikvision fetch skipped because settings have no company ownership.', [
                'hikvision_setting_id' => $settings->id,
            ]);
            $settings->markEventsFetchFailed('Hikvision settings have no company ownership.');

            return;
        }

        $companyId = (int) $settings->company_id;
        $hikvision ??= HikvisionService::forSetting($settings);
        $settings->markEventsFetchRunning();

        $timezone = (string) config('app.timezone', 'UTC');
        $date = filled($this->date)
            ? Carbon::parse($this->date, $timezone)->startOfDay()
            : null;

        $result = null;
        $fetchFailed = false;

        try {
            $result = filled($this->date)
                ? $hikvision->fetchAccessEvents($date)
                : $hikvision->fetchScheduledAccessEvents();
        } catch (RuntimeException $exception) {
            $fetchFailed = true;
            // #region agent log
            @file_put_contents(base_path('.cursor/debug-688778.log'), json_encode(['sessionId' => '688778', 'runId' => 'pre-fix', 'hypothesisId' => 'A', 'location' => 'FetchHikvisionAccessEventsJob.php:catch', 'message' => 'fetch RuntimeException', 'data' => ['error' => $exception->getMessage(), 'date' => $this->date, 'company_id' => $companyId], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND | LOCK_EX);
            // #endregion
            $settings->markEventsFetchFailed($exception->getMessage());
        } finally {
            if (! $fetchFailed) {
                if (filled($this->date) && $date instanceof Carbon) {
                    SyncHikvisionAttendanceJob::dispatch($date->toDateString(), $companyId);
                } elseif (! filled($this->date)) {
                    SyncHikvisionAttendanceJob::dispatch(null, $companyId);
                }
            }
        }

        if (! $fetchFailed && $result !== null) {
            // #region agent log
            @file_put_contents(base_path('.cursor/debug-688778.log'), json_encode(['sessionId' => '688778', 'runId' => 'pre-fix', 'hypothesisId' => 'B', 'location' => 'FetchHikvisionAccessEventsJob.php:success', 'message' => 'fetch completed', 'data' => ['fetched_count' => $result['fetched_count'] ?? null, 'message' => $result['message'] ?? null, 'date' => $this->date, 'company_id' => $companyId], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND | LOCK_EX);
            // #endregion
            $settings->markEventsFetchCompleted($result['message']);

            $jobId = $this->job ? $this->job->uuid() : null;
            if ($jobId) {
                JobRun::query()->where('correlation_id', $jobId)->update([
                    'message' => $result['message'],
                    'context' => [
                        'fetched_count' => $result['fetched_count'],
                        'date' => $this->date,
                        'company_id' => $companyId,
                    ],
                ]);
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        HikvisionSetting::find($this->hikvisionSettingId)?->markEventsFetchFailed(
            $exception->getMessage() !== '' ? $exception->getMessage() : 'Failed to fetch Hikvision access records.',
        );
    }
}
