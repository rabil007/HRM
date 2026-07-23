<?php

namespace App\Jobs;

use App\Enums\AnnouncementDeliveryStatus;
use App\Mail\AnnouncementMail;
use App\Models\AnnouncementDelivery;
use App\Support\Announcements\Actions\RefreshAnnouncementDeliveryStatus;
use App\Support\Announcements\BuildAnnouncementEmailContent;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

class DeliverAnnouncementEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public int $deliveryId) {}

    public function uniqueId(): string
    {
        return 'announcement-email-'.$this->deliveryId;
    }

    public function handle(
        BuildAnnouncementEmailContent $buildContent,
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

        if ($recipient === null || $announcement === null || ! filled($recipient->email)) {
            $delivery->update([
                'status' => AnnouncementDeliveryStatus::Skipped,
                'failed_at' => now(),
                'failure_reason' => 'Missing email address.',
                'attempt_count' => $delivery->attempt_count + 1,
            ]);
            if ($announcement) {
                $refreshStatus->handle($announcement);
            }

            return;
        }

        $content = $buildContent->handle($announcement, $recipient);

        Mail::to($recipient->email)->send(new AnnouncementMail(
            subjectLine: $content['subject'],
            bodyHtml: $content['html'],
        ));

        $delivery->update([
            'status' => AnnouncementDeliveryStatus::Sent,
            'sent_at' => now(),
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
            'failure_reason' => 'Email delivery failed.',
            'attempt_count' => $delivery->attempt_count + 1,
        ]);

        if ($delivery->recipient?->announcement) {
            app(RefreshAnnouncementDeliveryStatus::class)->handle($delivery->recipient->announcement);
        }
    }
}
