<?php

namespace App\Console\Commands;

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\User;
use App\Support\Announcements\Actions\PublishAnnouncement;
use Illuminate\Console\Command;

class PublishScheduledAnnouncementsCommand extends Command
{
    protected $signature = 'announcements:publish-scheduled';

    protected $description = 'Publish announcements whose scheduled_at time has passed';

    public function handle(PublishAnnouncement $publish): int
    {
        $due = Announcement::query()
            ->where('status', AnnouncementStatus::Scheduled)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(50)
            ->get();

        $published = 0;

        foreach ($due as $announcement) {
            $publisher = $announcement->publisher
                ?? $announcement->creator
                ?? User::query()->find($announcement->created_by);

            if ($publisher === null) {
                continue;
            }

            $publish->handle($announcement, $publisher);
            $published++;
        }

        $expired = Announcement::query()
            ->whereIn('status', [
                AnnouncementStatus::Published->value,
                AnnouncementStatus::PartiallyDelivered->value,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => AnnouncementStatus::Expired->value]);

        $this->info("Published {$published} scheduled announcement(s); expired {$expired}.");

        return self::SUCCESS;
    }
}
