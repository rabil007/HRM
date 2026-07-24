<?php

namespace App\Jobs;

use App\Enums\AnnouncementDeliveryStatus;
use App\Models\AnnouncementDelivery;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppService;
use App\Support\Announcements\Actions\RefreshAnnouncementDeliveryStatus;
use App\Support\Announcements\BuildAnnouncementPublicLinks;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DeliverAnnouncementWhatsAppJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public int $deliveryId) {}

    public function uniqueId(): string
    {
        return 'announcement-whatsapp-'.$this->deliveryId;
    }

    public function handle(
        WhatsAppService $whatsApp,
        RefreshAnnouncementDeliveryStatus $refreshStatus,
        BuildAnnouncementPublicLinks $publicLinks,
    ): void {
        $delivery = AnnouncementDelivery::query()
            ->with(['recipient.announcement.company', 'recipient.announcement.attachments'])
            ->find($this->deliveryId);

        if ($delivery === null) {
            return;
        }

        if (in_array($delivery->status, [
            AnnouncementDeliveryStatus::Sent,
            AnnouncementDeliveryStatus::Delivered,
            AnnouncementDeliveryStatus::Read,
        ], true)) {
            // #region agent log
            file_put_contents('/Users/mohammedrabil/Herd/OMS-HRM/.cursor/debug-17d3aa.log', json_encode(['sessionId' => '17d3aa', 'runId' => 'wa-resend-2', 'hypothesisId' => 'A', 'location' => 'DeliverAnnouncementWhatsAppJob.php:early-return', 'message' => 'skipped already-sent delivery', 'data' => ['delivery_id' => $this->deliveryId, 'status' => $delivery->status->value], 'timestamp' => (int) round(microtime(true) * 1000)], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
            // #endregion

            return;
        }

        $recipient = $delivery->recipient;
        $announcement = $recipient?->announcement;

        // #region agent log
        file_put_contents('/Users/mohammedrabil/Herd/OMS-HRM/.cursor/debug-17d3aa.log', json_encode(['sessionId' => '17d3aa', 'runId' => 'wa-resend-2', 'hypothesisId' => 'A,D', 'location' => 'DeliverAnnouncementWhatsAppJob.php:handle-entry', 'message' => 'whatsapp job executing', 'data' => ['delivery_id' => $this->deliveryId, 'status' => $delivery->status->value, 'has_recipient' => $recipient !== null, 'has_phone' => filled($recipient?->phone), 'queue_connection' => config('queue.default'), 'via_queue' => app()->runningInConsole()], 'timestamp' => (int) round(microtime(true) * 1000)], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
        // #endregion

        if ($recipient === null || $announcement === null || ! filled($recipient->phone)) {
            $delivery->update([
                'status' => AnnouncementDeliveryStatus::Skipped,
                'failed_at' => now(),
                'failure_reason' => 'Missing or invalid phone number.',
                'attempt_count' => $delivery->attempt_count + 1,
            ]);
            if ($announcement) {
                $refreshStatus->handle($announcement);
            }

            return;
        }

        $template = WhatsAppTemplate::query()
            ->where('slug', 'announcement')
            ->where('enabled', true)
            ->first();

        if ($template === null) {
            // #region agent log
            file_put_contents('/Users/mohammedrabil/Herd/OMS-HRM/.cursor/debug-17d3aa.log', json_encode(['sessionId' => '17d3aa', 'runId' => 'wa-send-rabil', 'hypothesisId' => 'D', 'location' => 'DeliverAnnouncementWhatsAppJob.php:template-missing', 'message' => 'announcement template missing', 'data' => ['delivery_id' => $this->deliveryId], 'timestamp' => (int) round(microtime(true) * 1000)], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
            // #endregion
            $delivery->update([
                'status' => AnnouncementDeliveryStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => 'WhatsApp announcement template is not configured.',
                'attempt_count' => $delivery->attempt_count + 1,
            ]);
            $refreshStatus->handle($announcement);

            return;
        }

        $companyName = (string) ($announcement->company?->name ?? config('app.name'));
        $shortBody = str($announcement->body_html)->stripTags()->limit(200)->toString();
        $shortSummary = $shortBody !== '' ? $shortBody : (string) $announcement->title;
        $publicUrl = $publicLinks->showUrl($recipient);

        $components = [
            [
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => $companyName],
                    ['type' => 'text', 'text' => (string) $announcement->title],
                    ['type' => 'text', 'text' => $shortSummary],
                    ['type' => 'text', 'text' => $announcement->priority->label()],
                    ['type' => 'text', 'text' => $publicUrl],
                ],
            ],
        ];

        // #region agent log
        file_put_contents('/Users/mohammedrabil/Herd/OMS-HRM/.cursor/debug-17d3aa.log', json_encode(['sessionId' => '17d3aa', 'runId' => 'wa-resend-2', 'hypothesisId' => 'A,B,C', 'location' => 'DeliverAnnouncementWhatsAppJob.php:before-send', 'message' => 'prepared announcement whatsapp payload', 'data' => ['delivery_id' => $this->deliveryId, 'announcement_id' => $announcement->id, 'employee_id' => $recipient->employee_id, 'phone_len' => strlen((string) $recipient->phone), 'phone_last4' => substr((string) $recipient->phone, -4), 'phone_digits_only' => (bool) preg_match('/^\d+$/', (string) $recipient->phone), 'meta_name' => $template->meta_name, 'meta_language' => $template->meta_language, 'param_count' => count($components[0]['parameters']), 'param_lengths' => array_map(fn ($p) => strlen((string) ($p['text'] ?? '')), $components[0]['parameters']), 'public_url_host' => parse_url($publicUrl, PHP_URL_HOST), 'public_url_scheme' => parse_url($publicUrl, PHP_URL_SCHEME)], 'timestamp' => (int) round(microtime(true) * 1000)], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
        // #endregion

        try {
            $result = $whatsApp->sendTemplate(
                (string) $recipient->phone,
                (string) $template->meta_name,
                (string) $template->meta_language,
                $components,
            );
        } catch (Throwable $exception) {
            // #region agent log
            file_put_contents('/Users/mohammedrabil/Herd/OMS-HRM/.cursor/debug-17d3aa.log', json_encode(['sessionId' => '17d3aa', 'runId' => 'wa-resend-2', 'hypothesisId' => 'B', 'location' => 'DeliverAnnouncementWhatsAppJob.php:send-exception', 'message' => 'sendTemplate threw', 'data' => ['delivery_id' => $this->deliveryId, 'exception_class' => $exception::class, 'exception_message' => $exception->getMessage()], 'timestamp' => (int) round(microtime(true) * 1000)], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
            // #endregion

            $delivery->update([
                'status' => AnnouncementDeliveryStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => $exception->getMessage(),
                'attempt_count' => $delivery->attempt_count + 1,
            ]);
            $refreshStatus->handle($announcement);

            return;
        }

        // #region agent log
        file_put_contents('/Users/mohammedrabil/Herd/OMS-HRM/.cursor/debug-17d3aa.log', json_encode(['sessionId' => '17d3aa', 'runId' => 'wa-resend-2', 'hypothesisId' => 'B,C,E', 'location' => 'DeliverAnnouncementWhatsAppJob.php:after-send', 'message' => 'sendTemplate returned', 'data' => ['delivery_id' => $this->deliveryId, 'success' => (bool) ($result['success'] ?? false), 'http_status' => $result['http_status'] ?? null, 'api_message' => $result['message'] ?? null, 'has_message_id' => filled($result['message_id'] ?? null), 'message_id_prefix' => is_string($result['message_id'] ?? null) ? substr((string) $result['message_id'], 0, 12) : null, 'normalized_phone_last4' => substr((string) ($result['normalized_phone'] ?? ''), -4)], 'timestamp' => (int) round(microtime(true) * 1000)], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
        // #endregion

        if (! ($result['success'] ?? false)) {
            $delivery->update([
                'status' => AnnouncementDeliveryStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => (string) ($result['message'] ?? 'WhatsApp delivery failed.'),
                'attempt_count' => $delivery->attempt_count + 1,
            ]);
            $refreshStatus->handle($announcement);

            return;
        }

        $delivery->update([
            'status' => AnnouncementDeliveryStatus::Sent,
            'sent_at' => now(),
            'provider_reference' => isset($result['message_id']) ? (string) $result['message_id'] : null,
            'attempt_count' => $delivery->attempt_count + 1,
            'failure_reason' => null,
            'failed_at' => null,
        ]);

        $refreshStatus->handle($announcement);
    }

    public function failed(Throwable $exception): void
    {
        $delivery = AnnouncementDelivery::query()->with('recipient.announcement')->find($this->deliveryId);

        if ($delivery === null) {
            return;
        }

        $delivery->update([
            'status' => AnnouncementDeliveryStatus::Failed,
            'failed_at' => now(),
            'failure_reason' => 'WhatsApp delivery failed.',
            'attempt_count' => $delivery->attempt_count + 1,
        ]);

        if ($delivery->recipient?->announcement) {
            app(RefreshAnnouncementDeliveryStatus::class)->handle($delivery->recipient->announcement);
        }
    }
}
