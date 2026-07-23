<?php

namespace App\Support\Announcements\Actions;

use App\Enums\AnnouncementDeliveryStatus;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;

final class RefreshAnnouncementDeliveryStatus
{
    public function handle(Announcement $announcement): void
    {
        if (! in_array($announcement->status, [
            AnnouncementStatus::Published,
            AnnouncementStatus::PartiallyDelivered,
            AnnouncementStatus::Publishing,
        ], true)) {
            return;
        }

        $hasFailed = AnnouncementDelivery::query()
            ->where('company_id', $announcement->company_id)
            ->where('status', AnnouncementDeliveryStatus::Failed)
            ->whereHas('recipient', fn ($q) => $q->where('announcement_id', $announcement->id))
            ->exists();

        $announcement->update([
            'status' => $hasFailed
                ? AnnouncementStatus::PartiallyDelivered
                : AnnouncementStatus::Published,
        ]);
    }
}
