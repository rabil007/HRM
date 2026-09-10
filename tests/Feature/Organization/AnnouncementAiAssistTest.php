<?php

use App\Enums\AnnouncementAiAssistAction;
use App\Enums\AnnouncementWhatsAppTemplatePurpose;
use App\Exceptions\AnnouncementAiAssistUnavailableException;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Services\AnnouncementContentAssistInterpreter;
use App\Support\Announcements\AnnouncementAiAssistResult;
use App\Support\Announcements\ListAnnouncementWhatsAppTemplates;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Prompts\AgentPrompt;

/**
 * @return array{user: User, company: Company}
 */
function makeAnnouncementAiAssistFixtures(): array
{
    $user = User::factory()->create();
    $code = 'AI'.fake()->unique()->numerify('##');
    $country = Country::query()->create([
        'code' => $code,
        'name' => 'AiAssistland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => $code,
        'name' => 'AI Assist Currency',
        'symbol' => 'A$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'AI Assist Co',
        'slug' => 'ai-assist-'.fake()->unique()->numerify('####'),
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
function announcementAiAssistPayload(array $overrides = []): array
{
    return array_merge([
        'action' => AnnouncementAiAssistAction::Improve->value,
        'instructions' => null,
        'title' => 'Draft title',
        'body_html' => '<p>Draft body for AI assist.</p>',
        'whatsapp_message' => 'Draft WhatsApp copy.',
    ], $overrides);
}

/**
 * @return array{
 *     title: string,
 *     main_body: string,
 *     whatsapp_message: string,
 *     template_purpose: string
 * }
 */
function fakeAnnouncementAiAssistResult(array $overrides = []): array
{
    return array_merge([
        'title' => 'Improved title',
        'main_body' => '<p>Improved body.</p>',
        'whatsapp_message' => 'Improved WhatsApp copy.',
        'template_purpose' => AnnouncementWhatsAppTemplatePurpose::Promotion->value,
    ], $overrides);
}

function enableAnnouncementAiAssist(?string $openaiKey = 'test-openai-key'): void
{
    storePlatformAiSettings([
        'enabled' => true,
        'openai_api_key' => ($openaiKey !== null && $openaiKey !== '') ? $openaiKey : null,
    ]);
}

test('authorized create or update user can call ai assist', function () {
    enableAnnouncementAiAssist();
    ensureAnnouncementWhatsAppTemplate();
    $promotion = ensureAnnouncementTitleBodyWhatsAppTemplate();

    ['user' => $user, 'company' => $company] = makeAnnouncementAiAssistFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['announcements.create']);

    AnnouncementContentAssistInterpreter::fake([
        fakeAnnouncementAiAssistResult(),
    ]);

    $this->postJson(route('organization.announcements.ai-assist'), announcementAiAssistPayload())
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('result.title', 'Improved title')
        ->assertJsonPath('result.main_body', fn ($html) => is_string($html) && str_contains($html, 'Improved body'))
        ->assertJsonPath('result.whatsapp_message', 'Improved WhatsApp copy.')
        ->assertJsonPath('result.template_purpose', AnnouncementWhatsAppTemplatePurpose::Promotion->value)
        ->assertJsonPath('result.suggested_template.id', $promotion->id)
        ->assertJsonPath('result.suggested_template.purpose', AnnouncementWhatsAppTemplatePurpose::Promotion->value);

    AnnouncementContentAssistInterpreter::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains(
        (string) $prompt->prompt,
        'Action: improve',
    ));
});

test('users without create or update permission cannot call ai assist', function () {
    enableAnnouncementAiAssist();
    ['user' => $user, 'company' => $company] = makeAnnouncementAiAssistFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['announcements.view']);

    AnnouncementContentAssistInterpreter::fake([
        fakeAnnouncementAiAssistResult(),
    ]);

    $this->postJson(route('organization.announcements.ai-assist'), announcementAiAssistPayload())
        ->assertForbidden();

    AnnouncementContentAssistInterpreter::assertNeverPrompted();
});

test('provider unavailable returns 503 with generic message', function () {
    enableAnnouncementAiAssist();
    ['user' => $user, 'company' => $company] = makeAnnouncementAiAssistFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['announcements.update']);

    AnnouncementContentAssistInterpreter::fake(function (): array {
        throw AnnouncementAiAssistUnavailableException::providerFailed();
    });

    $this->postJson(route('organization.announcements.ai-assist'), announcementAiAssistPayload())
        ->assertStatus(503)
        ->assertJsonPath('message', 'Announcement AI assistance is temporarily unavailable.');
});

test('suggested template maps purpose to enabled template and ignores client template ids', function () {
    enableAnnouncementAiAssist();
    ensureAnnouncementWhatsAppTemplate();
    $promotion = ensureAnnouncementTitleBodyWhatsAppTemplate();
    $legacy = ensureAnnouncementWhatsAppTemplate();

    ['user' => $user, 'company' => $company] = makeAnnouncementAiAssistFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['announcements.create', 'announcements.update']);

    AnnouncementContentAssistInterpreter::fake([
        fakeAnnouncementAiAssistResult([
            'title' => 'Promotion title',
            'main_body' => '<p>Promotion body.</p>',
            'whatsapp_message' => 'Promotion WhatsApp copy.',
            'template_purpose' => AnnouncementWhatsAppTemplatePurpose::Promotion->value,
        ]),
    ]);

    $this->postJson(route('organization.announcements.ai-assist'), announcementAiAssistPayload([
        'action' => AnnouncementAiAssistAction::SuggestTemplate->value,
        'whatsapp_template_id' => $legacy->id,
        'template_id' => $legacy->id,
        'meta_name' => 'client_forged_template',
        'company_id' => 999999,
    ]))->assertOk()
        ->assertJsonPath('result.suggested_template.id', $promotion->id)
        ->assertJsonPath('result.suggested_template.purpose', AnnouncementWhatsAppTemplatePurpose::Promotion->value);

    AnnouncementContentAssistInterpreter::assertPrompted(function (AgentPrompt $prompt) use ($legacy): bool {
        $text = (string) $prompt->prompt;

        return str_contains($text, 'Action: suggest_template')
            && ! str_contains($text, (string) $legacy->id)
            && ! str_contains($text, 'client_forged_template')
            && ! str_contains($text, '999999');
    });
});

test('malformed or empty ai output fails closed', function () {
    enableAnnouncementAiAssist();
    ['user' => $user, 'company' => $company] = makeAnnouncementAiAssistFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['announcements.create']);

    expect(fn () => AnnouncementAiAssistResult::fromDecoded(
        [
            'title' => '',
            'main_body' => '',
            'whatsapp_message' => '',
            'template_purpose' => '',
        ],
        app(ListAnnouncementWhatsAppTemplates::class),
    ))->toThrow(InvalidArgumentException::class);

    expect(fn () => AnnouncementAiAssistResult::fromDecoded(
        [
            'title' => 'Title',
            'main_body' => '<p>Body</p>',
            'whatsapp_message' => 'Message',
            'template_purpose' => 'not-a-real-purpose',
        ],
        app(ListAnnouncementWhatsAppTemplates::class),
    ))->toThrow(InvalidArgumentException::class);

    AnnouncementContentAssistInterpreter::fake([
        [
            'title' => '',
            'main_body' => '',
            'whatsapp_message' => '',
            'template_purpose' => '',
        ],
    ]);

    $this->postJson(route('organization.announcements.ai-assist'), announcementAiAssistPayload([
        'action' => AnnouncementAiAssistAction::Generate->value,
        'instructions' => 'Write a short safety notice',
    ]))->assertStatus(503)
        ->assertJsonPath('message', 'Announcement AI assistance is temporarily unavailable.');
});

test('ai assist does not create or publish announcements', function () {
    enableAnnouncementAiAssist();
    ensureAnnouncementTitleBodyWhatsAppTemplate();

    ['user' => $user, 'company' => $company] = makeAnnouncementAiAssistFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['announcements.create', 'announcements.publish']);

    $before = Announcement::query()->count();

    AnnouncementContentAssistInterpreter::fake([
        fakeAnnouncementAiAssistResult([
            'title' => 'AI only title',
            'main_body' => '<p>AI only body.</p>',
            'whatsapp_message' => 'AI only WhatsApp.',
            'template_purpose' => AnnouncementWhatsAppTemplatePurpose::General->value,
        ]),
    ]);

    $this->postJson(route('organization.announcements.ai-assist'), announcementAiAssistPayload([
        'action' => AnnouncementAiAssistAction::Generate->value,
        'instructions' => 'Create an announcement',
        'publish_mode' => 'send_now',
        'channels' => ['whatsapp', 'email'],
        'audiences' => [['type' => 'all_employees', 'id' => null]],
    ]))->assertOk()
        ->assertJsonPath('ok', true);

    expect(Announcement::query()->count())->toBe($before);
});
