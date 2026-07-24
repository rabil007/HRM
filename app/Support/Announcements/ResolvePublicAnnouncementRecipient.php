<?php

namespace App\Support\Announcements;

use App\Enums\AnnouncementStatus;
use App\Models\AnnouncementRecipient;

final class ResolvePublicAnnouncementRecipient
{
    public function handle(string $token): AnnouncementRecipient
    {
        abort_unless(is_string($token) && strlen($token) >= 32, 404);

        $recipient = AnnouncementRecipient::query()
            ->where('public_token', $token)
            ->with([
                'announcement' => fn ($query) => $query->with(['company:id,name', 'attachments']),
            ])
            ->first();

        abort_unless($recipient !== null, 404);

        $announcement = $recipient->announcement;
        abort_unless($announcement !== null, 404);
        abort_unless(in_array($announcement->status, [
            AnnouncementStatus::Published,
            AnnouncementStatus::PartiallyDelivered,
            AnnouncementStatus::Expired,
        ], true), 404);

        return $recipient;
    }
}
