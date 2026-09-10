<?php

use App\Enums\AnnouncementStatus;
use App\Enums\AnnouncementWhatsAppPayloadProfile;
use App\Enums\AnnouncementWhatsAppTemplatePurpose;
use App\Enums\WhatsAppTemplateCategory;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Support\Announcements\BuildAnnouncementWhatsAppContent;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, company: Company}
 */
function makeAnnouncementTemplateSelectionFixtures(): array
{
    $user = User::factory()->create();
    $code = 'TS'.fake()->unique()->numerify('##');
    $country = Country::query()->create([
        'code' => $code,
        'name' => 'Template Selectionland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => $code,
        'name' => 'Template Selection Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Template Selection Co',
        'slug' => 'template-sel-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return compact('user', 'company');
}

/**
 * @return array<string, mixed>
 */
function announcementTemplateSelectionPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Selected template notice',
        'body_html' => '<p>Body for template selection.</p>',
        'category' => 'general',
        'priority' => 'normal',
        'channels' => ['whatsapp'],
        'whatsapp_link' => 'https://example.com/notice',
        'whatsapp_message' => 'WhatsApp copy for the notice.',
        'audiences' => [['type' => 'all_employees', 'id' => null]],
        'publish_mode' => 'draft',
    ], $overrides);
}

test('create form options include enabled announcement templates only', function () {
    $legacy = ensureAnnouncementWhatsAppTemplate();
    $promotion = ensureAnnouncementTitleBodyWhatsAppTemplate();
    $disabled = ensureAnnouncementTitleBodyWhatsAppTemplate([
        'slug' => 'disabled_internal_notice',
        'label' => 'Disabled Internal',
        'meta_name' => 'disabled_internal_notice',
        'purpose' => AnnouncementWhatsAppTemplatePurpose::Internal,
        'enabled' => false,
        'sort_order' => 20,
    ]);
    WhatsAppTemplate::factory()->create([
        'slug' => 'document_sel_'.fake()->unique()->numerify('####'),
        'label' => 'Document Delivery Probe',
        'category' => WhatsAppTemplateCategory::Document,
        'meta_name' => 'document_sel_probe',
        'enabled' => true,
        'payload_profile' => null,
        'purpose' => null,
    ]);
    WhatsAppTemplate::factory()->create([
        'slug' => 'payroll_sel_'.fake()->unique()->numerify('####'),
        'label' => 'Payroll Slip',
        'category' => WhatsAppTemplateCategory::Payroll,
        'meta_name' => 'payroll_sel_probe',
        'enabled' => true,
        'payload_profile' => null,
        'purpose' => null,
    ]);

    ['user' => $user, 'company' => $company] = makeAnnouncementTemplateSelectionFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'announcements.view',
        'announcements.create',
        'announcements.update',
    ]);

    $this->get(route('organization.announcements.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/announcements/form')
            ->has('options.whatsapp_templates', 2)
            ->where('options.whatsapp_templates.0.id', $legacy->id)
            ->where('options.whatsapp_templates.1.id', $promotion->id)
            ->where('options.whatsapp_templates', fn ($templates) => collect($templates)
                ->pluck('id')
                ->doesntContain($disabled->id)
                && collect($templates)->every(fn ($template) => $template['payload_profile'] !== null)
            )
        );
});

test('selected template persists on store and update', function () {
    $legacy = ensureAnnouncementWhatsAppTemplate();
    $promotion = ensureAnnouncementTitleBodyWhatsAppTemplate();

    ['user' => $user, 'company' => $company] = makeAnnouncementTemplateSelectionFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'announcements.view',
        'announcements.create',
        'announcements.update',
    ]);

    Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'work_email' => 'worker@example.test',
    ]);

    $this->post(route('organization.announcements.store'), announcementTemplateSelectionPayload([
        'whatsapp_template_id' => $promotion->id,
    ]))->assertRedirect();

    $announcement = Announcement::query()->where('company_id', $company->id)->first();

    expect($announcement)->not->toBeNull()
        ->and($announcement->whatsapp_template_id)->toBe($promotion->id);

    $this->put(route('organization.announcements.update', $announcement), announcementTemplateSelectionPayload([
        'title' => 'Updated with legacy template',
        'whatsapp_template_id' => $legacy->id,
    ]))->assertRedirect();

    expect($announcement->fresh()->whatsapp_template_id)->toBe($legacy->id);
});

test('invalid disabled and document template ids are rejected with 422', function () {
    ensureAnnouncementWhatsAppTemplate();
    $disabled = ensureAnnouncementTitleBodyWhatsAppTemplate([
        'slug' => 'disabled_promo_select',
        'meta_name' => 'disabled_promo_select',
        'enabled' => false,
    ]);
    $document = WhatsAppTemplate::factory()->create([
        'slug' => 'document_invalid_'.fake()->unique()->numerify('####'),
        'label' => 'Document Invalid Probe',
        'category' => WhatsAppTemplateCategory::Document,
        'meta_name' => 'document_invalid_probe',
        'enabled' => true,
        'payload_profile' => null,
        'purpose' => null,
    ]);

    ['user' => $user, 'company' => $company] = makeAnnouncementTemplateSelectionFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'announcements.create',
        'announcements.update',
    ]);

    Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $this->postJson(route('organization.announcements.store'), announcementTemplateSelectionPayload([
        'whatsapp_template_id' => 999999999,
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['whatsapp_template_id']);

    $this->postJson(route('organization.announcements.store'), announcementTemplateSelectionPayload([
        'whatsapp_template_id' => $disabled->id,
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['whatsapp_template_id']);

    $this->postJson(route('organization.announcements.store'), announcementTemplateSelectionPayload([
        'whatsapp_template_id' => $document->id,
    ]))->assertUnprocessable()
        ->assertJsonValidationErrors(['whatsapp_template_id']);

    expect(Announcement::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('existing announcement without selection still builds via legacy fallback', function () {
    $legacy = ensureAnnouncementWhatsAppTemplate();
    ensureAnnouncementTitleBodyWhatsAppTemplate();

    ['user' => $user, 'company' => $company] = makeAnnouncementTemplateSelectionFixtures();

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Legacy fallback notice',
        'body_html' => '<p>Legacy body content.</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Draft,
        'channels' => ['whatsapp'],
        'whatsapp_link' => 'https://example.com/legacy',
        'whatsapp_message' => 'Legacy WhatsApp copy.',
        'whatsapp_template_id' => null,
        'created_by' => $user->id,
    ]);
    $announcement->setRelation('company', $company);

    $built = app(BuildAnnouncementWhatsAppContent::class)->handle($announcement);

    expect($built)->not->toBeNull()
        ->and($built['template']->id)->toBe($legacy->id)
        ->and($built['profile'])->toBe(AnnouncementWhatsAppPayloadProfile::LegacyV1)
        ->and($built['preview']['available'])->toBeTrue()
        ->and($built['components'][0]['parameters'])->toHaveCount(5);
});

test('cross-company announcement cannot be updated', function () {
    $promotion = ensureAnnouncementTitleBodyWhatsAppTemplate();
    ['user' => $user, 'company' => $company] = makeAnnouncementTemplateSelectionFixtures();
    $other = makeAnnouncementTemplateSelectionFixtures();

    $foreign = Announcement::query()->create([
        'company_id' => $other['company']->id,
        'title' => 'Other company draft',
        'body_html' => '<p>Secret</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Draft,
        'channels' => ['whatsapp'],
        'whatsapp_template_id' => $promotion->id,
        'created_by' => $other['user']->id,
    ]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'announcements.create',
        'announcements.update',
    ]);

    $this->putJson(route('organization.announcements.update', $foreign), announcementTemplateSelectionPayload([
        'title' => 'Should not update',
        'whatsapp_template_id' => $promotion->id,
    ]))->assertNotFound();

    expect($foreign->fresh()->title)->toBe('Other company draft');
});
