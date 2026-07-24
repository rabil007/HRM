<?php

namespace App\Support\Announcements\Actions;

use App\Models\AnnouncementRecipient;

final class AcknowledgeAnnouncement
{
    public function handle(AnnouncementRecipient $recipient): AnnouncementRecipient
    {
        $announcement = $recipient->announcement;
        abort_unless($announcement !== null, 404);
        abort_unless($announcement->requires_acknowledgement === true, 404);

        if ($recipient->acknowledged_at !== null) {
            return $recipient;
        }

        $recipient->update([
            'acknowledged_at' => now(),
            'read_at' => $recipient->read_at ?? now(),
        ]);

        activity()
            ->useLog('announcements')
            ->event('acknowledged')
            ->performedOn($announcement)
            ->tap(function ($activity) use ($recipient): void {
                $activity->company_id = $recipient->company_id;
            })
            ->withProperties([
                'announcement_recipient_id' => $recipient->id,
                'employee_id' => $recipient->employee_id,
            ])
            ->log('Announcement acknowledged via public link');

        return $recipient->fresh() ?? $recipient;
    }
}
