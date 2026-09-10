<?php

namespace App\Support\Announcements;

use App\Enums\AnnouncementChannel;
use App\Models\Announcement;

final class BuildAnnouncementChannelPreview
{
    public function __construct(
        private BuildAnnouncementEmailContent $emailContent,
        private ResolveAnnouncementWhatsAppTemplate $resolveWhatsAppTemplate,
    ) {}

    /**
     * @return array{
     *     channels: list<string>,
     *     in_app: array{title: string, body_html: string, priority_label: string, category_label: string}|null,
     *     email: array{subject: string, html: string}|null,
     *     whatsapp: array{template_name: string, template_language: string, body_text: string, company_name: string, view_link: string, available: bool, message: string|null}|null
     * }
     */
    public function handle(Announcement $announcement): array
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
                ? $this->whatsappPreview($announcement)
                : null,
        ];
    }

    /**
     * @return array{template_name: string, template_language: string, body_text: string, company_name: string, view_link: string, available: bool, message: string|null}
     */
    private function whatsappPreview(Announcement $announcement): array
    {
        $template = $this->resolveWhatsAppTemplate->handle();
        $companyName = (string) ($announcement->company?->name ?? config('app.name'));
        $message = AnnouncementWhatsAppMessage::for($announcement);
        $priority = $announcement->priority->label();
        $viewLink = AnnouncementWhatsAppMessage::viewLink($announcement);

        if ($template === null) {
            return [
                'template_name' => ResolveAnnouncementWhatsAppTemplate::SLUG,
                'template_language' => 'en',
                'body_text' => '',
                'company_name' => $companyName,
                'view_link' => $viewLink,
                'available' => false,
                'message' => 'WhatsApp announcement template is not configured.',
            ];
        }

        $bodyText = filled($template->body_preview)
            ? str_replace(
                [
                    '{{company}}',
                    '{{title}}',
                    '{{message}}',
                    '{{priority}}',
                    '{{url}}',
                    '{{1}}',
                    '{{2}}',
                    '{{3}}',
                    '{{4}}',
                    '{{5}}',
                ],
                [
                    $companyName,
                    $announcement->title,
                    $message,
                    $priority,
                    $viewLink,
                    $companyName,
                    $announcement->title,
                    $message,
                    $priority,
                    $viewLink,
                ],
                (string) $template->body_preview,
            )
            : "Hello,\nA company notice from {$companyName} is available for you.\n\nTitle: {$announcement->title}\nSummary: {$message}\nPriority: {$priority}\nView link: {$viewLink}";

        return [
            'template_name' => (string) $template->meta_name,
            'template_language' => (string) $template->meta_language,
            'body_text' => $bodyText,
            'company_name' => $companyName,
            'view_link' => $viewLink,
            'available' => true,
            'message' => null,
        ];
    }
}
