<?php

use App\Enums\AnnouncementWhatsAppPayloadProfile;
use App\Enums\AnnouncementWhatsAppTemplatePurpose;
use App\Enums\WhatsAppTemplateCategory;
use App\Enums\WhatsAppTemplateHeaderType;
use App\Models\WhatsAppTemplate;
use Illuminate\Support\Facades\DB;

test('canonical announcement whatsapp templates are seeded by the production-safe data migration', function () {
    $slugs = [
        'announcement_general',
        'announcement_promotion',
        'announcement_action_required',
        'announcement_reminder',
    ];

    foreach ($slugs as $slug) {
        expect(WhatsAppTemplate::query()->where('slug', $slug)->exists())->toBeTrue();
    }

    $general = WhatsAppTemplate::query()->where('slug', 'announcement_general')->firstOrFail();
    $legacy = WhatsAppTemplate::query()->where('slug', 'announcement')->firstOrFail();

    expect($general->is_default)->toBeTrue()
        ->and($general->enabled)->toBeTrue()
        ->and($general->meta_name)->toBe('employee_general_announcement')
        ->and($general->meta_language)->toBe('en')
        ->and($general->category)->toBe(WhatsAppTemplateCategory::Announcement)
        ->and($general->header_type)->toBe(WhatsAppTemplateHeaderType::Text)
        ->and($general->payload_profile)->toBe(AnnouncementWhatsAppPayloadProfile::TitleBodyV2)
        ->and($general->purpose)->toBe(AnnouncementWhatsAppTemplatePurpose::General)
        ->and($legacy->enabled)->toBeTrue()
        ->and($legacy->payload_profile)->toBe(AnnouncementWhatsAppPayloadProfile::LegacyV1)
        ->and($legacy->is_default)->toBeFalse()
        ->and($legacy->purpose)->toBeNull()
        ->and(WhatsAppTemplate::query()
            ->where('category', WhatsAppTemplateCategory::Announcement)
            ->where('is_default', true)
            ->count())->toBe(1);

    expect(WhatsAppTemplate::query()->where('slug', 'announcement_promotion')->value('meta_name'))
        ->toBe('employee_promotion_announcement')
        ->and(WhatsAppTemplate::query()->where('slug', 'announcement_action_required')->value('meta_name'))
        ->toBe('employee_action_required')
        ->and(WhatsAppTemplate::query()->where('slug', 'announcement_reminder')->value('meta_name'))
        ->toBe('employee_reminder');
});

test('announcement whatsapp template purposes are only the final four values', function () {
    expect(AnnouncementWhatsAppTemplatePurpose::values())->toBe([
        'general',
        'promotion',
        'action_required',
        'reminder',
    ])
        ->and(AnnouncementWhatsAppTemplatePurpose::ActionRequired->label())->toBe('Action Required')
        ->and(AnnouncementWhatsAppTemplatePurpose::tryFrom('safety'))->toBeNull()
        ->and(AnnouncementWhatsAppTemplatePurpose::tryFrom('internal'))->toBeNull()
        ->and(AnnouncementWhatsAppTemplatePurpose::tryFrom('crew'))->toBeNull()
        ->and(AnnouncementWhatsAppTemplatePurpose::tryFrom('training'))->toBeNull();
});

test('obsolete announcement purpose values can be normalized to null without enum cast failures', function () {
    DB::table('whatsapp_templates')->updateOrInsert(
        ['slug' => 'obsolete_purpose_probe'],
        [
            'label' => 'Obsolete Purpose Probe',
            'category' => WhatsAppTemplateCategory::Announcement->value,
            'meta_name' => 'obsolete_purpose_probe',
            'meta_language' => 'en',
            'header_type' => WhatsAppTemplateHeaderType::Text->value,
            'payload_profile' => AnnouncementWhatsAppPayloadProfile::TitleBodyV2->value,
            'purpose' => 'safety',
            'body_preview' => '{{1}}',
            'is_default' => false,
            'enabled' => false,
            'sort_order' => 999,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    );

    $canonical = AnnouncementWhatsAppTemplatePurpose::values();
    $obsolete = ['internal', 'safety', 'crew', 'training'];

    DB::table('whatsapp_templates')
        ->where('category', WhatsAppTemplateCategory::Announcement->value)
        ->whereNotNull('purpose')
        ->where(function ($query) use ($obsolete, $canonical): void {
            $query->whereIn('purpose', $obsolete)
                ->orWhereNotIn('purpose', $canonical);
        })
        ->update(['purpose' => null, 'updated_at' => now()]);

    expect(DB::table('whatsapp_templates')->where('slug', 'obsolete_purpose_probe')->value('purpose'))
        ->toBeNull();

    $model = WhatsAppTemplate::query()->where('slug', 'obsolete_purpose_probe')->firstOrFail();

    expect($model->purpose)->toBeNull();

    $model->forceDelete();
});
