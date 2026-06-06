<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessHikvisionWebhookEventJob;
use App\Models\HikvisionSetting;
use App\Support\Hikvision\HikvisionWebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class HikvisionWebhookController extends Controller
{
    public function __invoke(Request $request): Response|SymfonyResponse
    {
        if ($request->isMethod('GET')) {
            return $this->handleVerification($request);
        }

        return $this->handleEvent($request);
    }

    private function handleVerification(Request $request): Response
    {
        $batchId = (string) $request->header('X-Hook-Batch-Id', '');
        $timestamp = (string) $request->header('X-Hook-Timestamp', '');

        if ($batchId === '' || $timestamp === '') {
            return response('Bad Request', 400);
        }

        try {
            $secret = HikvisionSetting::current()->resolveWebhookSignSecret();
        } catch (\RuntimeException) {
            return response('Webhook sign secret is not configured.', 503);
        }

        $signature = HikvisionWebhookSignature::generate($secret, $timestamp, $batchId);

        return response('', 200)->header('X-Hook-Signature', $signature);
    }

    private function handleEvent(Request $request): Response
    {
        $settings = HikvisionSetting::current();

        if (! $this->requestIsAuthenticated($request, $settings)) {
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

    private function requestIsAuthenticated(Request $request, HikvisionSetting $settings): bool
    {
        $signature = (string) $request->header('X-Hook-Signature', '');
        $batchId = (string) $request->header('X-Hook-Batch-Id', '');
        $timestamp = (string) $request->header('X-Hook-Timestamp', '');

        if ($signature !== '' && $batchId !== '' && $timestamp !== '') {
            if (! HikvisionWebhookSignature::timestampIsFresh($timestamp)) {
                return false;
            }

            try {
                $secret = $settings->resolveWebhookSignSecret();
            } catch (\RuntimeException) {
                return false;
            }

            return HikvisionWebhookSignature::verify($secret, $timestamp, $batchId, $signature);
        }

        $storedToken = (string) ($settings->webhook_verify_token ?? '');
        $headerName = (string) config('hikvision.webhook_verify_header', 'X-HCC-Webhook-Token');
        $providedToken = (string) $request->header($headerName, '');

        if ($storedToken === '') {
            return false;
        }

        return hash_equals($storedToken, $providedToken);
    }
}
