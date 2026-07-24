<?php

namespace App\Support\Announcements;

use App\Enums\AnnouncementChannel;
use App\Enums\WhatsAppTemplateCategory;
use App\Models\Announcement;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Str;

final class BuildAnnouncementChannelPreview
{
    public function __construct(private BuildAnnouncementEmailContent $emailContent) {}

    /**
     * @return array{
     *     channels: list<string>,
     *     in_app: array{title: string, body_html: string, priority_label: string, category_label: string}|null,
     *     email: array{subject: string, html: string}|null,
     *     whatsapp: array{template_name: string, template_language: string, body_text: string, company_name: string}|null
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
     * @return array{template_name: string, template_language: string, body_text: string, company_name: string}
     */
    private function whatsappPreview(Announcement $announcement): array
    {
        $template = WhatsAppTemplate::query()
            ->where('slug', 'announcement')
            ->where('enabled', true)
            ->first()
            ?? WhatsAppTemplate::query()
                ->enabled()
                ->forCategory(WhatsAppTemplateCategory::General)
                ->orderByDesc('is_default')
                ->orderBy('sort_order')
                ->first();

        $companyName = (string) ($announcement->company?->name ?? config('app.name'));
        $shortBody = Str::of($announcement->body_html)->stripTags()->limit(200)->toString();
        $message = $shortBody !== '' ? $shortBody : $announcement->title;
        $priority = $announcement->priority->label();

        $bodyText = filled($template?->body_preview)
            ? str_replace(
                ['{{company}}', '{{title}}', '{{message}}', '{{priority}}', '{{1}}', '{{2}}', '{{3}}', '{{4}}'],
                [$companyName, $announcement->title, $message, $priority, $companyName, $announcement->title, $message, $priority],
                (string) $template->body_preview,
            )
            : "{$companyName} — {$announcement->title}: {$message}. Priority: {$priority}.";

        return [
            'template_name' => (string) ($template?->meta_name ?? 'announcement'),
            'template_language' => (string) ($template?->meta_language ?? 'en'),
            'body_text' => $bodyText,
            'company_name' => $companyName,
        ];
    }
}
