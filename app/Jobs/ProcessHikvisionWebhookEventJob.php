<?php

namespace App\Jobs;

use App\Models\HikvisionAccessEvent;
use App\Support\Hikvision\HikvisionWebhookDebugLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessHikvisionWebhookEventJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    public function handle(): void
    {
        HikvisionWebhookDebugLog::info('Processing webhook job', [
            'payload' => HikvisionWebhookDebugLog::summarizePayload($this->payload),
        ]);

        $stored = HikvisionAccessEvent::upsertFromWebhook($this->payload);

        HikvisionWebhookDebugLog::info('Webhook job finished', [
            'stored' => $stored !== null,
            'event_id' => $stored?->id,
            'person_name' => $stored?->person_name,
            'occurrence_time' => $stored?->occurrence_time?->toIso8601String(),
        ]);
    }
}
