<?php

namespace App\Http\Controllers\Webhooks;

use App\Enums\AnnouncementDeliveryStatus;
use App\Http\Controllers\Controller;
use App\Models\AnnouncementDelivery;
use App\Models\WhatsAppSetting;
use App\Support\Announcements\Actions\RefreshAnnouncementDeliveryStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class WhatsAppWebhookController extends Controller
{
    public function __invoke(Request $request): Response|SymfonyResponse|string
    {
        if ($request->isMethod('GET')) {
            return $this->verify($request);
        }

        $this->processStatuses($request);

        return response()->noContent();
    }

    private function verify(Request $request): Response|SymfonyResponse|string
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        if ($mode !== 'subscribe' || $challenge === '') {
            return response('Forbidden', 403);
        }

        $storedToken = WhatsAppSetting::current()->webhook_verify_token;

        if (! filled($storedToken) || ! hash_equals((string) $storedToken, $token)) {
            return response('Forbidden', 403);
        }

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    private function processStatuses(Request $request): void
    {
        $entries = $request->input('entry', []);

        // #region agent log
        file_put_contents('/Users/mohammedrabil/Herd/OMS-HRM/.cursor/debug-17d3aa.log', json_encode(['sessionId' => '17d3aa', 'runId' => 'wa-resend-2', 'hypothesisId' => 'B', 'location' => 'WhatsAppWebhookController.php:processStatuses', 'message' => 'webhook statuses received', 'data' => ['entry_count' => is_array($entries) ? count($entries) : 0, 'has_statuses' => is_array($entries) && collect($entries)->contains(fn ($e) => is_array($e['changes'][0]['value']['statuses'] ?? null))], 'timestamp' => (int) round(microtime(true) * 1000)], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
        // #endregion

        if (! is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            $changes = is_array($entry) ? ($entry['changes'] ?? []) : [];

            if (! is_array($changes)) {
                continue;
            }

            foreach ($changes as $change) {
                $statuses = is_array($change) ? ($change['value']['statuses'] ?? []) : [];

                if (! is_array($statuses)) {
                    continue;
                }

                foreach ($statuses as $statusPayload) {
                    if (! is_array($statusPayload)) {
                        continue;
                    }

                    $this->applyStatus($statusPayload);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $statusPayload
     */
    private function applyStatus(array $statusPayload): void
    {
        $messageId = (string) ($statusPayload['id'] ?? '');
        $status = strtolower((string) ($statusPayload['status'] ?? ''));

        if ($messageId === '' || $status === '') {
            return;
        }

        $delivery = AnnouncementDelivery::query()
            ->where('provider_reference', $messageId)
            ->with('recipient.announcement')
            ->first();

        // #region agent log
        file_put_contents('/Users/mohammedrabil/Herd/OMS-HRM/.cursor/debug-17d3aa.log', json_encode(['sessionId' => '17d3aa', 'runId' => 'wa-resend-2', 'hypothesisId' => 'B', 'location' => 'WhatsAppWebhookController.php:applyStatus', 'message' => 'provider status update', 'data' => ['status' => $status, 'message_id_prefix' => substr($messageId, 0, 12), 'delivery_found' => $delivery !== null, 'delivery_id' => $delivery?->id, 'errors' => $statusPayload['errors'][0]['message'] ?? ($statusPayload['errors'][0]['title'] ?? null)], 'timestamp' => (int) round(microtime(true) * 1000)], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
        // #endregion

        if ($delivery === null) {
            return;
        }

        $mapped = match ($status) {
            'sent' => AnnouncementDeliveryStatus::Sent,
            'delivered' => AnnouncementDeliveryStatus::Delivered,
            'read' => AnnouncementDeliveryStatus::Read,
            'failed' => AnnouncementDeliveryStatus::Failed,
            default => null,
        };

        if ($mapped === null) {
            return;
        }

        $updates = [
            'status' => $mapped,
        ];

        if ($mapped === AnnouncementDeliveryStatus::Delivered) {
            $updates['delivered_at'] = now();
        }

        if ($mapped === AnnouncementDeliveryStatus::Read) {
            $updates['read_at'] = now();
            $updates['delivered_at'] = $delivery->delivered_at ?? now();
        }

        if ($mapped === AnnouncementDeliveryStatus::Failed) {
            $updates['failed_at'] = now();
            $updates['failure_reason'] = 'WhatsApp provider reported failure.';
        }

        if ($mapped === AnnouncementDeliveryStatus::Sent && $delivery->sent_at === null) {
            $updates['sent_at'] = now();
        }

        $delivery->update($updates);

        if ($delivery->recipient?->announcement) {
            app(RefreshAnnouncementDeliveryStatus::class)->handle($delivery->recipient->announcement);
        }
    }
}
