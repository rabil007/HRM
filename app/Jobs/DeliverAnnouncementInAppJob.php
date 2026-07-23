<?php

namespace App\Jobs;

use App\Enums\AnnouncementDeliveryStatus;
use App\Models\AnnouncementDelivery;
use App\Support\Announcements\Actions\RefreshAnnouncementDeliveryStatus;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DeliverAnnouncementInAppJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public int $deliveryId) {}

    public function uniqueId(): string
    {
        return 'announcement-in-app-'.$this->deliveryId;
    }

    public function handle(RefreshAnnouncementDeliveryStatus $refreshStatus): void
    {
        $delivery = AnnouncementDelivery::query()->with('recipient.announcement')->find($this->deliveryId);

        if ($delivery === null) {
            return;
        }

        if ($delivery->status === AnnouncementDeliveryStatus::Sent) {
            return;
        }

        if ($delivery->recipient?->user_id === null) {
            $delivery->update([
                'status' => AnnouncementDeliveryStatus::Skipped,
                'failed_at' => now(),
                'failure_reason' => 'No linked user account.',
                'attempt_count' => $delivery->attempt_count + 1,
            ]);
            $refreshStatus->handle($delivery->recipient->announcement);

            return;
        }

        $delivery->update([
            'status' => AnnouncementDeliveryStatus::Sent,
            'sent_at' => now(),
            'attempt_count' => $delivery->attempt_count + 1,
        ]);

        $refreshStatus->handle($delivery->recipient->announcement);
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
            'failure_reason' => 'In-app delivery failed.',
            'attempt_count' => $delivery->attempt_count + 1,
        ]);

        if ($delivery->recipient?->announcement) {
            app(RefreshAnnouncementDeliveryStatus::class)->handle($delivery->recipient->announcement);
        }
    }
}
