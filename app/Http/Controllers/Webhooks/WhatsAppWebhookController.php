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
