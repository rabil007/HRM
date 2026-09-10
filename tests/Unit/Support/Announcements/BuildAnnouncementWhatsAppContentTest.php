<?php

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementPriority;
use App\Enums\AnnouncementStatus;
use App\Enums\AnnouncementWhatsAppPayloadProfile;
use App\Enums\WhatsAppTemplateCategory;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\WhatsAppTemplate;
use App\Support\Announcements\AnnouncementWhatsAppMessage;
use App\Support\Announcements\BuildAnnouncementWhatsAppContent;

/**
 * @return array{company: Company, announcement: Announcement}
 */
function makeWhatsAppContentFixtures(array $announcementOverrides = []): array
{
    $code = 'WC'.fake()->unique()->numerify('##');
    $country = Country::query()->create([
        'code' => $code,
        'name' => 'WhatsApp Contentland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => $code,
        'name' => 'WhatsApp Content Currency',
        'symbol' => 'W$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'WhatsApp Content Co',
        'slug' => 'wa-content-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $announcement = new Announcement(array_merge([
        'company_id' => $company->id,
        'title' => 'Safety Drill Notice',
        'body_html' => '<p>Report to muster station B immediately.</p>',
        'category' => AnnouncementCategory::Safety,
        'priority' => AnnouncementPriority::Urgent,
        'status' => AnnouncementStatus::Draft,
        'channels' => ['whatsapp'],
        'whatsapp_link' => 'https://example.com/drill',
        'whatsapp_message' => null,
        'whatsapp_template_id' => null,
    ], $announcementOverrides));
    $announcement->setRelation('company', $company);
    $announcement->setRelation('attachments', collect());

    return compact('company', 'announcement');
}

test('legacy profile builds five body parameters including company title summary priority and link', function () {
    $template = ensureAnnouncementWhatsAppTemplate();
    ['announcement' => $announcement] = makeWhatsAppContentFixtures([
        'whatsapp_template_id' => $template->id,
        'whatsapp_message' => 'Custom short summary for crew.',
        'whatsapp_link' => 'https://example.com/drill',
    ]);

    $built = app(BuildAnnouncementWhatsAppContent::class)->handle($announcement);

    expect($built)->not->toBeNull()
        ->and($built['profile'])->toBe(AnnouncementWhatsAppPayloadProfile::LegacyV1)
        ->and($built['components'])->toHaveCount(1)
        ->and($built['components'][0]['type'])->toBe('body');

    $parameters = $built['components'][0]['parameters'];

    expect($parameters)->toHaveCount(5)
        ->and($parameters[0]['text'])->toBe('WhatsApp Content Co')
        ->and($parameters[1]['text'])->toBe('Safety Drill Notice')
        ->and($parameters[2]['text'])->toBe('Custom short summary for crew.')
        ->and($parameters[3]['text'])->toBe('Urgent')
        ->and($parameters[4]['text'])->toBe('https://example.com/drill')
        ->and($built['preview']['available'])->toBeTrue()
        ->and($built['preview']['payload_profile'])->toBe(AnnouncementWhatsAppPayloadProfile::LegacyV1->value);
});

test('legacy blank link becomes N/A template parameter', function () {
    $template = ensureAnnouncementWhatsAppTemplate();
    ['announcement' => $announcement] = makeWhatsAppContentFixtures([
        'whatsapp_template_id' => $template->id,
        'whatsapp_link' => null,
        'whatsapp_message' => 'No link provided.',
    ]);

    $built = app(BuildAnnouncementWhatsAppContent::class)->handle($announcement);
    $parameters = $built['components'][0]['parameters'];

    expect($parameters[4]['text'])->toBe(AnnouncementWhatsAppMessage::EMPTY_VIEW_LINK)
        ->and($built['preview']['view_link'])->toBe(AnnouncementWhatsAppMessage::EMPTY_VIEW_LINK);
});

test('title body v2 builds header title and body message without priority', function () {
    $template = ensureAnnouncementTitleBodyWhatsAppTemplate();
    ['announcement' => $announcement] = makeWhatsAppContentFixtures([
        'whatsapp_template_id' => $template->id,
        'whatsapp_message' => 'Promotion details for the crew.',
        'whatsapp_link' => 'https://example.com/promo',
        'priority' => AnnouncementPriority::High,
    ]);

    $built = app(BuildAnnouncementWhatsAppContent::class)->handle($announcement);

    expect($built)->not->toBeNull()
        ->and($built['profile'])->toBe(AnnouncementWhatsAppPayloadProfile::TitleBodyV2)
        ->and($built['components'])->toHaveCount(2)
        ->and($built['components'][0]['type'])->toBe('header')
        ->and($built['components'][0]['parameters'][0]['text'])->toBe('Safety Drill Notice')
        ->and($built['components'][1]['type'])->toBe('body');

    $bodyText = $built['components'][1]['parameters'][0]['text'];
    $serialized = json_encode($built['components']);

    expect($bodyText)->toBe('Promotion details for the crew. https://example.com/promo')
        ->and($serialized)->not->toContain('High')
        ->and($serialized)->not->toContain('Urgent')
        ->and($built['preview']['header_text'])->toBe('Safety Drill Notice')
        ->and($built['preview']['view_link'])->toBe('https://example.com/promo');
});

test('title body v2 blank link does not produce N/A and is omitted from body', function () {
    $template = ensureAnnouncementTitleBodyWhatsAppTemplate();
    ['announcement' => $announcement] = makeWhatsAppContentFixtures([
        'whatsapp_template_id' => $template->id,
        'whatsapp_message' => 'Message without a link.',
        'whatsapp_link' => '',
    ]);

    $built = app(BuildAnnouncementWhatsAppContent::class)->handle($announcement);
    $bodyText = $built['components'][1]['parameters'][0]['text'];

    expect($bodyText)->toBe('Message without a link.')
        ->and($bodyText)->not->toContain(AnnouncementWhatsAppMessage::EMPTY_VIEW_LINK)
        ->and($built['preview']['view_link'])->toBeNull()
        ->and(json_encode($built['components']))->not->toContain('N/A');
});

test('blank whatsapp_message falls back to plain text from body_html', function () {
    $template = ensureAnnouncementTitleBodyWhatsAppTemplate();
    ['announcement' => $announcement] = makeWhatsAppContentFixtures([
        'whatsapp_template_id' => $template->id,
        'body_html' => '<p>Report to <strong>muster station B</strong> immediately.</p>',
        'whatsapp_message' => null,
        'whatsapp_link' => null,
    ]);

    $built = app(BuildAnnouncementWhatsAppContent::class)->handle($announcement);
    $bodyText = $built['components'][1]['parameters'][0]['text'];

    expect($bodyText)->toBe('Report to muster station B immediately.')
        ->and($built['preview']['resolved_message'])->toBe('Report to muster station B immediately.');
});

test('custom whatsapp_message is used when set', function () {
    $template = ensureAnnouncementWhatsAppTemplate();
    ['announcement' => $announcement] = makeWhatsAppContentFixtures([
        'whatsapp_template_id' => $template->id,
        'body_html' => '<p>This HTML body should be ignored for WhatsApp.</p>',
        'whatsapp_message' => 'Use this custom WhatsApp copy instead.',
    ]);

    $built = app(BuildAnnouncementWhatsAppContent::class)->handle($announcement);
    $summary = $built['components'][0]['parameters'][2]['text'];

    expect($summary)->toBe('Use this custom WhatsApp copy instead.')
        ->and($summary)->not->toContain('ignored');
});

test('missing or disabled selected template returns null and previewOrError unavailable', function () {
    ensureAnnouncementWhatsAppTemplate();
    $disabled = ensureAnnouncementTitleBodyWhatsAppTemplate([
        'slug' => 'disabled_promotion',
        'meta_name' => 'disabled_promotion',
        'enabled' => false,
    ]);

    ['announcement' => $announcement] = makeWhatsAppContentFixtures([
        'whatsapp_template_id' => $disabled->id,
    ]);

    $builder = app(BuildAnnouncementWhatsAppContent::class);

    expect($builder->handle($announcement))->toBeNull();

    $preview = $builder->previewOrError($announcement);

    expect($preview['available'])->toBeFalse()
        ->and($preview['template_id'])->toBe($disabled->id)
        ->and($preview['message'])->toBe('The selected WhatsApp template is missing, disabled, or incompatible.');

    $missingPreview = $builder->previewOrError($announcement, 999999);

    expect($builder->handle($announcement, 999999))->toBeNull()
        ->and($missingPreview['available'])->toBeFalse()
        ->and($missingPreview['template_id'])->toBe(999999);
});

test('no configured legacy template yields unavailable preview', function () {
    WhatsAppTemplate::query()->delete();

    ['announcement' => $announcement] = makeWhatsAppContentFixtures([
        'whatsapp_template_id' => null,
    ]);

    $builder = app(BuildAnnouncementWhatsAppContent::class);

    expect($builder->handle($announcement))->toBeNull();

    $preview = $builder->previewOrError($announcement);

    expect($preview['available'])->toBeFalse()
        ->and($preview['message'])->toBe('WhatsApp announcement template is not configured.');
});

test('document category templates are never resolved for announcements', function () {
    ensureAnnouncementWhatsAppTemplate();
    $document = WhatsAppTemplate::factory()->create([
        'slug' => 'document_for_announcement_reject_'.fake()->unique()->numerify('####'),
        'label' => 'Document Reject Probe',
        'category' => WhatsAppTemplateCategory::Document,
        'meta_name' => 'document_reject_probe',
        'enabled' => true,
        'payload_profile' => AnnouncementWhatsAppPayloadProfile::TitleBodyV2,
        'purpose' => null,
    ]);

    ['announcement' => $announcement] = makeWhatsAppContentFixtures([
        'whatsapp_template_id' => $document->id,
    ]);

    $builder = app(BuildAnnouncementWhatsAppContent::class);

    expect($document->category)->toBe(WhatsAppTemplateCategory::Document)
        ->and($builder->handle($announcement))->toBeNull()
        ->and($builder->previewOrError($announcement)['available'])->toBeFalse();
});
