<?php

namespace App\Jobs;

use App\Models\HikvisionAccessEvent;
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
        HikvisionAccessEvent::upsertFromWebhook($this->payload);
    }
}
