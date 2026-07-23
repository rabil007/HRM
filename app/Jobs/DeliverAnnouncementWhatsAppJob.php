<?php

namespace App\Jobs;

use App\Enums\AnnouncementDeliveryStatus;
use App\Enums\WhatsAppTemplateCategory;
use App\Models\AnnouncementDelivery;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppService;
use App\Support\Announcements\Actions\RefreshAnnouncementDeliveryStatus;
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
            return;
        }

        $recipient = $delivery->recipient;
        $announcement = $recipient?->announcement;

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
            ->first()
            ?? WhatsAppTemplate::query()
                ->enabled()
                ->forCategory(WhatsAppTemplateCategory::General)
                ->orderByDesc('is_default')
                ->orderBy('sort_order')
                ->first();

        if ($template === null) {
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

        $components = [
            [
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'text' => $companyName],
                    ['type' => 'text', 'text' => (string) $announcement->title],
                    ['type' => 'text', 'text' => $shortBody !== '' ? $shortBody : (string) $announcement->title],
                    ['type' => 'text', 'text' => $announcement->priority->label()],
                ],
            ],
        ];

        $result = $whatsApp->sendTemplate(
            (string) $recipient->phone,
            (string) $template->meta_name,
            (string) $template->meta_language,
            $components,
        );

        if (! ($result['success'] ?? false)) {
            $delivery->update([
                'status' => AnnouncementDeliveryStatus::Failed,
                'failed_at' => now(),
                'failure_reason' => 'WhatsApp delivery failed.',
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
