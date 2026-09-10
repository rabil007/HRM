<?php

use App\Enums\AnnouncementWhatsAppPayloadProfile;
use App\Enums\AnnouncementWhatsAppTemplatePurpose;
use App\Enums\WhatsAppTemplateCategory;
use App\Enums\WhatsAppTemplateHeaderType;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
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

test('canonical template migration down preserves referenced rows with safe purpose', function () {
    $referenced = WhatsAppTemplate::query()->where('slug', 'announcement_action_required')->firstOrFail();
    $unreferenced = WhatsAppTemplate::query()->where('slug', 'announcement_reminder')->firstOrFail();

    expect($referenced->purpose)->toBe(AnnouncementWhatsAppTemplatePurpose::ActionRequired)
        ->and($unreferenced->purpose)->toBe(AnnouncementWhatsAppTemplatePurpose::Reminder);

    $code = 'RB'.fake()->unique()->numerify('##');
    $country = Country::query()->create([
        'code' => $code,
        'name' => 'Rollbackland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => $code,
        'name' => 'Rollback Currency',
        'symbol' => 'R$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Rollback Co',
        'slug' => 'rollback-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Referenced canonical template',
        'body_html' => '<p>Keep this FK.</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => 'draft',
        'channels' => ['whatsapp'],
        'whatsapp_template_id' => $referenced->id,
    ]);

    $migration = require database_path('migrations/2026_09_10_140000_seed_canonical_announcement_whatsapp_templates.php');
    $migration->down();

    expect(DB::table('whatsapp_templates')->where('slug', 'announcement_action_required')->exists())->toBeTrue()
        ->and(DB::table('whatsapp_templates')->where('slug', 'announcement_reminder')->exists())->toBeFalse()
        ->and(DB::table('whatsapp_templates')->where('id', $referenced->id)->value('purpose'))->toBeNull()
        ->and((bool) DB::table('whatsapp_templates')->where('id', $referenced->id)->value('is_default'))->toBeFalse()
        ->and($announcement->fresh()->whatsapp_template_id)->toBe($referenced->id);

    $migration->up();
});
