<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessHikvisionWebhookEventJob;
use App\Models\HikvisionSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HikvisionWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $settings = HikvisionSetting::current();
        $storedToken = (string) ($settings->webhook_verify_token ?? '');
        $headerName = (string) config('hikvision.webhook_verify_header', 'X-HCC-Webhook-Token');
        $providedToken = (string) $request->header($headerName, '');

        if ($storedToken === '' || ! hash_equals($storedToken, $providedToken)) {
            return response('Forbidden', 403);
        }

        if (! $settings->webhook_enabled) {
            return response('Webhook ingestion is disabled.', 403);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        if ($payload === []) {
            return response()->noContent();
        }

        ProcessHikvisionWebhookEventJob::dispatch($payload);
        $settings->markWebhookEventReceived();

        return response()->noContent();
    }
}
