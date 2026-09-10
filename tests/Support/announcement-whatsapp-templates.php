<?php

use App\Enums\AnnouncementWhatsAppPayloadProfile;
use App\Enums\AnnouncementWhatsAppTemplatePurpose;
use App\Enums\WhatsAppTemplateCategory;
use App\Enums\WhatsAppTemplateHeaderType;
use App\Models\WhatsAppTemplate;

function ensureAnnouncementWhatsAppTemplate(array $overrides = []): WhatsAppTemplate
{
    return WhatsAppTemplate::query()->updateOrCreate(
        ['slug' => $overrides['slug'] ?? 'announcement'],
        array_merge([
            'label' => 'Announcement',
            'category' => WhatsAppTemplateCategory::Announcement,
            'meta_name' => 'announcement',
            'meta_language' => 'en_US',
            'header_type' => WhatsAppTemplateHeaderType::None,
            'payload_profile' => AnnouncementWhatsAppPayloadProfile::LegacyV1,
            'purpose' => AnnouncementWhatsAppTemplatePurpose::General,
            'body_preview' => '{{1}} — {{2}}: {{3}}. Priority: {{4}}. Open: {{5}}',
            'is_default' => true,
            'enabled' => true,
            'sort_order' => 1,
        ], $overrides),
    );
}

function ensureAnnouncementTitleBodyWhatsAppTemplate(array $overrides = []): WhatsAppTemplate
{
    return WhatsAppTemplate::query()->updateOrCreate(
        ['slug' => $overrides['slug'] ?? 'employee_promotion_announcement'],
        array_merge([
            'label' => 'Promotion Announcement',
            'category' => WhatsAppTemplateCategory::Announcement,
            'meta_name' => 'employee_promotion_announcement',
            'meta_language' => 'en',
            'header_type' => WhatsAppTemplateHeaderType::Text,
            'payload_profile' => AnnouncementWhatsAppPayloadProfile::TitleBodyV2,
            'purpose' => AnnouncementWhatsAppTemplatePurpose::Promotion,
            'body_preview' => "Here's an update from OMS:\n\n{{1}}\n\nThank you.",
            'is_default' => false,
            'enabled' => true,
            'sort_order' => 10,
        ], $overrides),
    );
}
