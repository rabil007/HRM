<?php

namespace App\Support\Announcements;

use App\Enums\AnnouncementWhatsAppPayloadProfile;
use App\Enums\WhatsAppTemplateCategory;
use App\Models\Announcement;
use App\Models\WhatsAppTemplate;

final class ResolveAnnouncementWhatsAppTemplate
{
    public const LEGACY_SLUG = 'announcement';

    /**
     * @deprecated Use LEGACY_SLUG
     */
    public const SLUG = self::LEGACY_SLUG;

    public function handle(?Announcement $announcement = null, ?int $templateId = null): ?WhatsAppTemplate
    {
        $selectedId = $templateId
            ?? ($announcement !== null ? $announcement->whatsapp_template_id : null);

        if ($selectedId !== null) {
            return $this->findEnabledAnnouncementTemplate((int) $selectedId);
        }

        return $this->legacyFallback();
    }

    public function findEnabledAnnouncementTemplate(int $templateId): ?WhatsAppTemplate
    {
        return WhatsAppTemplate::query()
            ->enabled()
            ->forCategory(WhatsAppTemplateCategory::Announcement)
            ->whereKey($templateId)
            ->whereNotNull('payload_profile')
            ->first();
    }

    public function legacyFallback(): ?WhatsAppTemplate
    {
        return WhatsAppTemplate::query()
            ->enabled()
            ->where('slug', self::LEGACY_SLUG)
            ->where(function ($query): void {
                $query->where('category', WhatsAppTemplateCategory::Announcement->value)
                    ->orWhere('category', WhatsAppTemplateCategory::General->value);
            })
            ->first();
    }

    public function profileFor(WhatsAppTemplate $template): AnnouncementWhatsAppPayloadProfile
    {
        if ($template->payload_profile instanceof AnnouncementWhatsAppPayloadProfile) {
            return $template->payload_profile;
        }

        if (is_string($template->payload_profile) && $template->payload_profile !== '') {
            return AnnouncementWhatsAppPayloadProfile::from($template->payload_profile);
        }

        // Historical rows without an explicit profile stay on the 5-variable contract.
        return AnnouncementWhatsAppPayloadProfile::LegacyV1;
    }
}
