<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessHikvisionWebhookEventJob;
use App\Models\HikvisionSetting;
use App\Support\Hikvision\HikvisionWebhookDebugLog;
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

        HikvisionWebhookDebugLog::info('GET verification request received', [
            'batch_id' => $batchId !== '' ? $batchId : null,
            'timestamp' => $timestamp !== '' ? $timestamp : null,
            'ip' => $request->ip(),
        ]);

        if ($batchId === '' || $timestamp === '') {
            HikvisionWebhookDebugLog::info('GET verification rejected: missing headers');

            return response('Bad Request', 400);
        }

        try {
            $secret = HikvisionSetting::current()->resolveWebhookSignSecret();
        } catch (\RuntimeException $exception) {
            HikvisionWebhookDebugLog::info('GET verification rejected: sign secret missing', [
                'error' => $exception->getMessage(),
            ]);

            return response('Webhook sign secret is not configured.', 503);
        }

        $signature = HikvisionWebhookSignature::generate($secret, $timestamp, $batchId);

        HikvisionWebhookDebugLog::info('GET verification succeeded', [
            'batch_id' => $batchId,
        ]);

        return response('', 200)->header('X-Hook-Signature', $signature);
    }

    private function handleEvent(Request $request): Response
    {
        $settings = HikvisionSetting::current();

        HikvisionWebhookDebugLog::info('POST event request received', [
            'ip' => $request->ip(),
            'batch_id' => $request->header('X-Hook-Batch-Id'),
            'timestamp' => $request->header('X-Hook-Timestamp'),
            'has_signature' => $request->header('X-Hook-Signature') !== null,
            'webhook_enabled' => $settings->webhook_enabled,
        ]);

        $authFailure = $this->authenticationFailureReason($request, $settings);

        if ($authFailure !== null) {
            HikvisionWebhookDebugLog::info('POST event rejected: authentication failed', [
                'reason' => $authFailure,
            ]);

            return response('Forbidden', 403);
        }

        if (! $settings->webhook_enabled) {
            HikvisionWebhookDebugLog::info('POST event rejected: ingestion disabled');

            return response('Webhook ingestion is disabled.', 403);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        if ($payload === []) {
            HikvisionWebhookDebugLog::info('POST event accepted with empty payload');

            return response()->noContent();
        }

        HikvisionWebhookDebugLog::info('POST event accepted, dispatching job', [
            'payload' => HikvisionWebhookDebugLog::summarizePayload($payload),
        ]);

        ProcessHikvisionWebhookEventJob::dispatch($payload);
        $settings->markWebhookEventReceived();

        return response()->noContent();
    }

    private function requestIsAuthenticated(Request $request, HikvisionSetting $settings): bool
    {
        return $this->authenticationFailureReason($request, $settings) === null;
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
