<?php

namespace App\Support\Announcements;

use App\Models\Announcement;
use App\Models\WhatsAppTemplate;

final class BuildAnnouncementWhatsAppTemplatePayload
{
    public function __construct(private ResolveAnnouncementWhatsAppTemplate $resolveTemplate) {}

    /**
     * @return array{
     *     template: WhatsAppTemplate,
     *     components: list<array{type: string, parameters: list<array{type: string, text: string}>}>
     * }|null
     */
    public function handle(Announcement $announcement): ?array
    {
        $template = $this->resolveTemplate->handle();

        if ($template === null) {
            return null;
        }

        $announcement->loadMissing('company:id,name');

        $companyName = AnnouncementWhatsAppMessage::templateParameter(
            (string) ($announcement->company?->name ?? config('app.name')),
        );
        $title = AnnouncementWhatsAppMessage::templateParameter((string) $announcement->title);
        $shortSummary = AnnouncementWhatsAppMessage::for($announcement);
        $priority = AnnouncementWhatsAppMessage::templateParameter($announcement->priority->label());
        $linkParameter = AnnouncementWhatsAppMessage::templateParameter(
            AnnouncementWhatsAppMessage::viewLink($announcement),
        );

        return [
            'template' => $template,
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $companyName],
                        ['type' => 'text', 'text' => $title],
                        ['type' => 'text', 'text' => $shortSummary],
                        ['type' => 'text', 'text' => $priority],
                        ['type' => 'text', 'text' => $linkParameter],
                    ],
                ],
            ],
        ];
    }
}
