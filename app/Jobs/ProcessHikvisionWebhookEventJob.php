<?php

namespace App\Jobs;

use App\Models\HikvisionSetting;
use App\Models\JobRun;
use App\Support\Hikvision\HikvisionFetchOrigin;
use App\Support\Settings\CompanyTimezone;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessHikvisionWebhookEventJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload  Retained for queue serialization compatibility; never trusted as attendance data.
     */
    public function __construct(public array $payload, public int $hikvisionSettingId) {}

    public function handle(): void
    {
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

        if (! $settings->isConfigured()) {
            Log::warning('Hikvision webhook job skipped because integration is not configured for API fetch.', [
                'hikvision_setting_id' => $settings->id,
                'company_id' => $settings->company_id,
            ]);

            $this->updateJobRunMessage('Ignored webhook event: Hikvision API credentials are not configured.');

            return;
        }

        $companyId = (int) $settings->company_id;
        $timezone = CompanyTimezone::forCompany($companyId);
        $targetDate = now($timezone)->toDateString();

        FetchHikvisionAccessEventsJob::dispatch(
            $settings->id,
            $targetDate,
            HikvisionFetchOrigin::WebhookTrigger,
        );

        $this->updateJobRunMessage(
            "Accepted webhook notification for company {$companyId}; dispatched authoritative access-events fetch for {$targetDate}.",
            [
                'company_id' => $companyId,
                'event_date' => $targetDate,
                'fetch_origin' => HikvisionFetchOrigin::WebhookTrigger->value,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function updateJobRunMessage(string $message, array $context = []): void
    {
        $jobId = $this->job ? $this->job->uuid() : null;

        if ($jobId === null) {
            return;
        }

        $attributes = ['message' => $message];

        if ($context !== []) {
            $attributes['context'] = $context;
        }

        JobRun::query()->where('correlation_id', $jobId)->update($attributes);
    }
}
