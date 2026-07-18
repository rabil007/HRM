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
    public function __invoke(Request $request, string $publicIntegrationId): Response|SymfonyResponse
    {
        $settings = HikvisionSetting::findActiveWebhookIntegration($publicIntegrationId);

        if ($settings === null) {
            abort(404);
        }

        if ($request->isMethod('GET')) {
            return $this->handleVerification($request, $settings);
        }

        return $this->handleEvent($request, $settings);
    }

    private function handleVerification(Request $request, HikvisionSetting $settings): Response
    {
        $batchId = (string) $request->header('X-Hook-Batch-Id', '');
        $timestamp = (string) $request->header('X-Hook-Timestamp', '');

        if ($batchId === '' || $timestamp === '') {
            return response('Bad Request', 400);
        }

        try {
            $secret = $settings->resolveWebhookSignSecret();
        } catch (\RuntimeException) {
            abort(404);
        }

        $signature = HikvisionWebhookSignature::generate($secret, $timestamp, $batchId);

        return response('', 200)->header('X-Hook-Signature', $signature);
    }

    private function handleEvent(Request $request, HikvisionSetting $settings): Response
    {
        if ($this->authenticationFailureReason($request, $settings) !== null) {
            abort(404);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        if ($payload === []) {
            return response()->noContent();
        }

        ProcessHikvisionWebhookEventJob::dispatch($payload, $settings->id);
        $settings->markWebhookEventReceived();

        return response()->noContent();
    }

    private function authenticationFailureReason(Request $request, HikvisionSetting $settings): ?string
    {
        $signature = (string) $request->header('X-Hook-Signature', '');
        $batchId = (string) $request->header('X-Hook-Batch-Id', '');
        $timestamp = (string) $request->header('X-Hook-Timestamp', '');

        if ($signature !== '' && $batchId !== '' && $timestamp !== '') {
            if (! HikvisionWebhookSignature::timestampIsFresh($timestamp)) {
                return 'signed_request_timestamp_stale_or_invalid';
            }

            try {
                $secret = $settings->resolveWebhookSignSecret();
            } catch (\RuntimeException) {
                return 'signed_request_secret_not_configured';
            }

            if (! HikvisionWebhookSignature::verify($secret, $timestamp, $batchId, $signature)) {
                return 'signed_request_signature_mismatch';
            }

            return null;
        }

        $storedToken = (string) ($settings->webhook_verify_token ?? '');
        $headerName = (string) config('hikvision.webhook_verify_header', 'X-HCC-Webhook-Token');
        $providedToken = (string) $request->header($headerName, '');

        if ($storedToken === '') {
            return 'legacy_token_not_configured';
        }

        if (! hash_equals($storedToken, $providedToken)) {
            return 'legacy_token_mismatch';
        }

        return null;
    }
}
