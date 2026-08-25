<?php

namespace App\Jobs;

use App\Models\HikvisionReconciliation;
use App\Models\HikvisionSetting;
use App\Models\JobRun;
use App\Services\HikvisionService;
use App\Support\Attendance\DispatchHikvisionAttendanceSync;
use App\Support\Hikvision\HikvisionFetchOrigin;
use App\Support\Settings\CompanyTimezone;
use Carbon\CarbonInterface;
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

    public function __construct(
        public int $hikvisionSettingId,
        public ?string $date = null,
        public string|HikvisionFetchOrigin|null $origin = null,
    ) {
        $this->timeout = 180;
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

        $companyTimezone = CompanyTimezone::forCompany($companyId);
        $targetDateCarbon = filled($this->date)
            ? Carbon::parse($this->date, $companyTimezone)->startOfDay()
            : now($companyTimezone)->subDay()->startOfDay();
        $targetDateString = $targetDateCarbon->toDateString();

        $resolvedOrigin = $this->resolveOrigin($companyTimezone);

        $result = null;
        $fetchFailed = false;

        try {
            $result = filled($this->date)
                ? $hikvision->fetchAccessEvents($targetDateCarbon)
                : $hikvision->fetchScheduledAccessEvents();
        } catch (RuntimeException $exception) {
            $fetchFailed = true;
            $settings->markEventsFetchFailed($exception->getMessage());
        } finally {
            if (! $fetchFailed) {
                $this->dispatchAttendanceSync($resolvedOrigin, $companyId, $targetDateCarbon, $targetDateString);
            }
        }

        if (! $fetchFailed && $result !== null) {
            $settings->markEventsFetchCompleted($result['message']);

            $fetchedCount = (int) ($result['fetched_count'] ?? 0);
            $deviceCount = (int) ($result['device_count'] ?? 0);
            $mobileCount = (int) ($result['mobile_count'] ?? 0);

            $isPastDate = $targetDateCarbon->lt(now($companyTimezone)->startOfDay());
            if ($isPastDate) {
                HikvisionReconciliation::markCompleted(
                    $companyId,
                    $targetDateString,
                    $resolvedOrigin,
                    $fetchedCount,
                    $deviceCount,
                    $mobileCount,
                );
            }

            $jobId = $this->job ? $this->job->uuid() : null;
            if ($jobId) {
                JobRun::query()->where('correlation_id', $jobId)->update([
                    'message' => $result['message'],
                    'context' => [
                        'fetched_count' => $fetchedCount,
                        'device_count' => $deviceCount,
                        'mobile_count' => $mobileCount,
                        'date' => $targetDateString,
                        'company_id' => $companyId,
                        'fetch_origin' => $resolvedOrigin->value,
                    ],
                ]);
            }
        }
    }

    public function resolveOrigin(string $companyTimezone): HikvisionFetchOrigin
    {
        if ($this->origin instanceof HikvisionFetchOrigin) {
            return $this->origin;
        }

        if (filled($this->origin)) {
            return HikvisionFetchOrigin::fromValue((string) $this->origin);
        }

        if (! filled($this->date)) {
            return HikvisionFetchOrigin::ScheduledReconciliation;
        }

        $today = now($companyTimezone)->toDateString();
        if ($this->date === $today) {
            return HikvisionFetchOrigin::ScheduledToday;
        }

        return HikvisionFetchOrigin::Manual;
    }

    public function failed(Throwable $exception): void
    {
        $settings = HikvisionSetting::find($this->hikvisionSettingId);
        $settings?->markEventsFetchFailed(
            $exception->getMessage() !== '' ? $exception->getMessage() : 'Failed to fetch Hikvision access records.',
        );

        if (filled($this->date) && $settings?->company_id !== null) {
            HikvisionReconciliation::markFailed((int) $settings->company_id, $this->date, $exception->getMessage());
        }
    }

    private function dispatchAttendanceSync(
        HikvisionFetchOrigin $origin,
        int $companyId,
        CarbonInterface $targetDateCarbon,
        string $targetDateString,
    ): void {
        if ($origin === HikvisionFetchOrigin::WebhookTrigger) {
            app(DispatchHikvisionAttendanceSync::class)->dispatchForWindow(
                $targetDateCarbon->copy()->startOfDay(),
                $targetDateCarbon->copy()->endOfDay(),
                $companyId,
            );

            return;
        }

        SyncHikvisionAttendanceJob::dispatch($targetDateString, $companyId);
    }
}
