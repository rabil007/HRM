<?php

namespace App\Support\Announcements;

use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementDeliveryStatus;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\AnnouncementRecipient;

final class AnnouncementDeliverySummary
{
    /**
     * @return array{
     *     total_recipients: int,
     *     in_app_sent: int,
     *     email_sent: int,
     *     whatsapp_sent: int,
     *     failed: int,
     *     skipped: int
     * }
     */
    public static function for(Announcement $announcement): array
    {
        $recipientIds = AnnouncementRecipient::query()
            ->where('announcement_id', $announcement->id)
            ->pluck('id');

        $deliveries = AnnouncementDelivery::query()
            ->whereIn('announcement_recipient_id', $recipientIds)
            ->get(['channel', 'status']);

        $sentStatuses = [
            AnnouncementDeliveryStatus::Sent->value,
            AnnouncementDeliveryStatus::Delivered->value,
            AnnouncementDeliveryStatus::Read->value,
        ];

        return [
            'total_recipients' => $recipientIds->count(),
            'in_app_sent' => $deliveries
                ->where('channel', AnnouncementChannel::InApp)
                ->filter(fn ($d) => in_array($d->status->value, $sentStatuses, true))
                ->count(),
            'email_sent' => $deliveries
                ->where('channel', AnnouncementChannel::Email)
                ->filter(fn ($d) => in_array($d->status->value, $sentStatuses, true))
                ->count(),
            'whatsapp_sent' => $deliveries
                ->where('channel', AnnouncementChannel::WhatsApp)
                ->filter(fn ($d) => in_array($d->status->value, $sentStatuses, true))
                ->count(),
            'failed' => $deliveries->where('status', AnnouncementDeliveryStatus::Failed)->count(),
            'skipped' => $deliveries->where('status', AnnouncementDeliveryStatus::Skipped)->count(),
        ];
    }
}
