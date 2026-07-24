<?php

namespace App\Support\Announcements;

use App\Models\AnnouncementAttachment;
use App\Models\AnnouncementRecipient;

final class BuildAnnouncementPublicLinks
{
    public function showUrl(AnnouncementRecipient $recipient): string
    {
        return route('public.announcements.show', [
            'token' => $recipient->public_token,
        ]);
    }

    public function attachmentUrl(AnnouncementRecipient $recipient, AnnouncementAttachment $attachment): string
    {
        return route('public.announcements.attachments.download', [
            'token' => $recipient->public_token,
            'attachment' => $attachment->id,
        ]);
    }
}
