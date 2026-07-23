<?php

namespace App\Support\Announcements\Actions;

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class CancelScheduledAnnouncement
{
    public function handle(Announcement $announcement, User $user): Announcement
    {
        if ($announcement->status !== AnnouncementStatus::Scheduled) {
            throw ValidationException::withMessages([
                'status' => 'Only scheduled announcements can be cancelled.',
            ]);
        }

        $announcement->update([
            'status' => AnnouncementStatus::Cancelled,
            'cancelled_by' => $user->id,
            'scheduled_at' => null,
        ]);

        activity()
            ->useLog('announcements')
            ->event('cancelled')
            ->causedBy($user)
            ->performedOn($announcement)
            ->tap(fn ($activity) => $activity->company_id = $announcement->company_id)
            ->log('Scheduled announcement cancelled');

        return $announcement->fresh() ?? $announcement;
    }
}
