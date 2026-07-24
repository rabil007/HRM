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

        return $this->render($announcement, $attachmentLinks);
    }

    /**
     * @return array{subject: string, html: string}
     */
    public function preview(Announcement $announcement): array
    {
        $attachmentLinks = $announcement->attachments->map(fn ($attachment): array => [
            'name' => $attachment->original_name,
            'url' => '#',
        ])->all();

        return $this->render($announcement, $attachmentLinks);
    }

    /**
     * @param  list<array{name: string, url: string}>  $attachmentLinks
     * @return array{subject: string, html: string}
     */
    private function render(Announcement $announcement, array $attachmentLinks): array
    {
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
