<?php

namespace App\Support\Announcements;

use App\Models\Announcement;
use App\Models\WhatsAppTemplate;

/**
 * @deprecated Prefer BuildAnnouncementWhatsAppContent. Kept as a thin alias for existing call sites.
 */
final class BuildAnnouncementWhatsAppTemplatePayload
{
    public function __construct(private BuildAnnouncementWhatsAppContent $content) {}

    /**
     * @return array{
     *     template: WhatsAppTemplate,
     *     components: list<array{type: string, parameters: list<array{type: string, text: string}>}>
     * }|null
     */
    public function handle(Announcement $announcement, ?int $templateId = null): ?array
    {
        $built = $this->content->handle($announcement, $templateId);

        if ($built === null) {
            return null;
        }

        return [
            'template' => $built['template'],
            'components' => $built['components'],
        ];
    }
}
