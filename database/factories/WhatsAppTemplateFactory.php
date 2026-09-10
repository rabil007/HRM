<?php

namespace Database\Factories;

use App\Enums\AnnouncementWhatsAppPayloadProfile;
use App\Enums\AnnouncementWhatsAppTemplatePurpose;
use App\Enums\WhatsAppTemplateCategory;
use App\Enums\WhatsAppTemplateHeaderType;
use App\Models\WhatsAppTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WhatsAppTemplate>
 */
class WhatsAppTemplateFactory extends Factory
{
    protected $model = WhatsAppTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = Str::slug($this->faker->unique()->words(2, true), '_');

        return [
            'slug' => $slug,
            'label' => Str::headline(str_replace('_', ' ', $slug)),
            'category' => WhatsAppTemplateCategory::Document,
            'meta_name' => $slug,
            'meta_language' => 'en',
            'header_type' => WhatsAppTemplateHeaderType::Document,
            'payload_profile' => null,
            'purpose' => null,
            'body_preview' => 'Hello {{name}}, please find the attached document.',
            'is_default' => false,
            'enabled' => true,
            'sort_order' => 0,
        ];
    }

    public function documentDefault(): static
    {
        return $this->state(fn () => [
            'slug' => 'document_delivery',
            'label' => 'Document delivery',
            'category' => WhatsAppTemplateCategory::Document,
            'meta_name' => 'document_delivery',
            'meta_language' => 'en',
            'header_type' => WhatsAppTemplateHeaderType::Document,
            'payload_profile' => null,
            'purpose' => null,
            'body_preview' => 'Hello {{name}}, Please find the attached document from Overseas Marine Services. Thank you.',
            'is_default' => true,
        ]);
    }

    public function announcementLegacy(): static
    {
        return $this->state(fn () => [
            'slug' => 'announcement',
            'label' => 'Announcement',
            'category' => WhatsAppTemplateCategory::Announcement,
            'meta_name' => 'employee_announcement_notice',
            'meta_language' => 'en_US',
            'header_type' => WhatsAppTemplateHeaderType::None,
            'payload_profile' => AnnouncementWhatsAppPayloadProfile::LegacyV1,
            'purpose' => AnnouncementWhatsAppTemplatePurpose::General,
            'body_preview' => '{{1}} — {{2}}: {{3}}. Priority: {{4}}. Open: {{5}}',
            'is_default' => true,
            'enabled' => true,
            'sort_order' => 1,
        ]);
    }

    public function announcementTitleBody(
        string $slug = 'employee_promotion_announcement',
        string $label = 'Promotion Announcement',
        AnnouncementWhatsAppTemplatePurpose $purpose = AnnouncementWhatsAppTemplatePurpose::Promotion,
        bool $enabled = false,
    ): static {
        return $this->state(fn () => [
            'slug' => $slug,
            'label' => $label,
            'category' => WhatsAppTemplateCategory::Announcement,
            'meta_name' => $slug,
            'meta_language' => 'en',
            'header_type' => WhatsAppTemplateHeaderType::Text,
            'payload_profile' => AnnouncementWhatsAppPayloadProfile::TitleBodyV2,
            'purpose' => $purpose,
            'body_preview' => "Here's an update from OMS:\n\n{{1}}\n\nThank you.",
            'is_default' => false,
            'enabled' => $enabled,
            'sort_order' => 10,
        ]);
    }
}
