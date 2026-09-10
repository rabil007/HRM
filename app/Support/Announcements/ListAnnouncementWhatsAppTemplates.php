<?php

namespace App\Support\Announcements;

use App\Enums\WhatsAppTemplateCategory;
use App\Models\WhatsAppTemplate;

final class ListAnnouncementWhatsAppTemplates
{
    /**
     * @return list<array{
     *     id: int,
     *     label: string,
     *     slug: string,
     *     meta_name: string,
     *     meta_language: string,
     *     payload_profile: string,
     *     purpose: string|null,
     *     body_preview: string,
     *     header_type: string,
     *     is_legacy: bool
     * }>
     */
    public function handle(): array
    {
        return WhatsAppTemplate::query()
            ->enabled()
            ->forCategory(WhatsAppTemplateCategory::Announcement)
            ->whereNotNull('payload_profile')
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->map(fn (WhatsAppTemplate $template): array => [
                'id' => (int) $template->id,
                'label' => (string) $template->label,
                'slug' => (string) $template->slug,
                'meta_name' => (string) $template->meta_name,
                'meta_language' => (string) $template->meta_language,
                'payload_profile' => $template->payload_profile instanceof \BackedEnum
                    ? $template->payload_profile->value
                    : (string) $template->payload_profile,
                'purpose' => $template->purpose instanceof \BackedEnum
                    ? $template->purpose->value
                    : ($template->purpose !== null ? (string) $template->purpose : null),
                'body_preview' => (string) $template->body_preview,
                'header_type' => $template->header_type->value,
                'is_legacy' => $template->slug === ResolveAnnouncementWhatsAppTemplate::LEGACY_SLUG,
            ])
            ->values()
            ->all();
    }

    public function findByPurpose(string $purpose): ?WhatsAppTemplate
    {
        return WhatsAppTemplate::query()
            ->enabled()
            ->forCategory(WhatsAppTemplateCategory::Announcement)
            ->whereNotNull('payload_profile')
            ->where('purpose', $purpose)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->first();
    }
}
