<?php

namespace App\Support\Announcements;

use App\Enums\AnnouncementChannel;
use App\Models\Announcement;

final class BuildAnnouncementChannelPreview
{
    public function __construct(
        private BuildAnnouncementEmailContent $emailContent,
        private BuildAnnouncementWhatsAppContent $whatsAppContent,
    ) {}

    /**
     * @return array{
     *     channels: list<string>,
     *     in_app: array{title: string, body_html: string, priority_label: string, category_label: string}|null,
     *     email: array{subject: string, html: string}|null,
     *     whatsapp: array{
     *         template_id: int|null,
     *         template_label: string|null,
     *         template_name: string,
     *         template_language: string,
     *         payload_profile: string|null,
     *         header_type: string,
     *         header_text: string|null,
     *         body_text: string,
     *         resolved_message: string|null,
     *         view_link: string|null,
     *         available: bool,
     *         message: string|null
     *     }|null
     * }
     */
    public function handle(Announcement $announcement, ?int $whatsAppTemplateId = null): array
    {
        $announcement->loadMissing(['company:id,name', 'attachments']);

        $channelSet = collect($announcement->channels ?? [])
            ->filter(fn ($channel): bool => in_array($channel, AnnouncementChannel::values(), true))
            ->values()
            ->all();

        return [
            'channels' => $channelSet,
            'in_app' => in_array(AnnouncementChannel::InApp->value, $channelSet, true)
                ? [
                    'title' => $announcement->title,
                    'body_html' => $announcement->body_html,
                    'priority_label' => $announcement->priority->label(),
                    'category_label' => $announcement->category->label(),
                ]
                : null,
            'email' => in_array(AnnouncementChannel::Email->value, $channelSet, true)
                ? $this->emailContent->preview($announcement)
                : null,
            'whatsapp' => in_array(AnnouncementChannel::WhatsApp->value, $channelSet, true)
                ? $this->whatsAppContent->previewOrError($announcement, $whatsAppTemplateId)
                : null,
        ];
    }
}
