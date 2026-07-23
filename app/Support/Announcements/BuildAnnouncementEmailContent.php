<?php

namespace App\Support\Announcements;

use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use Illuminate\Support\Facades\View;

final class BuildAnnouncementEmailContent
{
    public function __construct(private BuildAnnouncementPublicLinks $publicLinks) {}

    /**
     * @return array{subject: string, html: string}
     */
    public function handle(Announcement $announcement, AnnouncementRecipient $recipient): array
    {
        $attachmentLinks = $announcement->attachments->map(fn ($attachment): array => [
            'name' => $attachment->original_name,
            'url' => $this->publicLinks->attachmentUrl($recipient, $attachment),
        ])->all();

        $html = View::make('mail.announcement', [
            'title' => $announcement->title,
            'bodyHtml' => $announcement->body_html,
            'priority' => $announcement->priority->label(),
            'publishedAt' => $announcement->published_at?->format('d M Y H:i') ?? now()->format('d M Y H:i'),
            'companyName' => (string) ($announcement->company?->name ?? config('app.name')),
            'attachmentLinks' => $attachmentLinks,
        ])->render();

        return [
            'subject' => $announcement->priority->label().' Announcement — '.$announcement->title,
            'html' => $html,
        ];
    }
}
