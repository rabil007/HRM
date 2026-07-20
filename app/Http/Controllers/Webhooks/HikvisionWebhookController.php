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
    public function __invoke(Request $request, ?string $publicIntegrationId = null): Response|SymfonyResponse
    {
        $settings = filled($publicIntegrationId)
            ? HikvisionSetting::findActiveWebhookIntegration($publicIntegrationId)
            : HikvisionSetting::findLegacyWebhookIntegration();

        // #region agent log
        @file_put_contents(base_path('.cursor/debug-688778.log'), json_encode(['sessionId' => '688778', 'runId' => 'post-fix', 'hypothesisId' => 'W1', 'location' => 'HikvisionWebhookController.php:__invoke', 'message' => 'webhook request received', 'data' => ['method' => $request->method(), 'public_integration_id' => $publicIntegrationId, 'legacy_route' => ! filled($publicIntegrationId), 'settings_found' => $settings !== null, 'company_id' => $settings?->company_id], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND | LOCK_EX);
        // #endregion

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
        $authFailure = $this->authenticationFailureReason($request, $settings);

        // #region agent log
        @file_put_contents(base_path('.cursor/debug-688778.log'), json_encode(['sessionId' => '688778', 'runId' => 'post-fix', 'hypothesisId' => 'W2', 'location' => 'HikvisionWebhookController.php:handleEvent', 'message' => 'webhook auth check', 'data' => ['company_id' => $settings->company_id, 'auth_failure' => $authFailure, 'has_signature' => $request->header('X-Hook-Signature') !== null, 'payload_keys' => array_keys($request->json()->all() ?? [])], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND | LOCK_EX);
        // #endregion

        if ($authFailure !== null) {
            abort(404);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        if ($payload === []) {
            return response()->noContent();
        }

        ProcessHikvisionWebhookEventJob::dispatch($payload, $settings->id);
        $settings->markWebhookEventReceived();

        // #region agent log
        @file_put_contents(base_path('.cursor/debug-688778.log'), json_encode(['sessionId' => '688778', 'runId' => 'post-fix', 'hypothesisId' => 'W3', 'location' => 'HikvisionWebhookController.php:handleEvent', 'message' => 'webhook job dispatched', 'data' => ['setting_id' => $settings->id, 'company_id' => $settings->company_id, 'has_list' => isset($payload['list']), 'batch_id' => $payload['batchId'] ?? null], 'timestamp' => (int) (microtime(true) * 1000)])."\n", FILE_APPEND | LOCK_EX);
        // #endregion

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
