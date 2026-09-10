<?php

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;
use App\Services\WhatsAppService;
use App\Support\Announcements\AnnouncementWhatsAppMessage;
use App\Support\Announcements\BuildAnnouncementWhatsAppContent;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;

/**
 * @return array{user: User, company: Company}
 */
function makeAnnouncementWhatsAppLinkProfileFixtures(): array
{
    $user = User::factory()->create([
        'email' => 'link-profile@company.test',
    ]);
    $code = 'LP'.fake()->unique()->numerify('##');
    $country = Country::query()->create([
        'code' => $code,
        'name' => 'Link Profileland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => $code,
        'name' => 'Link Profile Currency',
        'symbol' => 'L$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Link Profile Co',
        'slug' => 'link-profile-'.fake()->unique()->numerify('####'),
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

    Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $user->id,
        'work_email' => 'link-profile@company.test',
        'phone' => '+971509998877',
    ]);

    return compact('user', 'company');
}

/**
 * @return array<string, mixed>
 */
function announcementWhatsAppLinkPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Link profile notice',
        'body_html' => '<p>Body for link profile validation.</p>',
        'category' => 'general',
        'priority' => 'normal',
        'channels' => ['whatsapp'],
        'whatsapp_message' => 'Short WhatsApp copy.',
        'whatsapp_link' => 'https://example.com/notice',
        'audiences' => [['type' => 'all_employees', 'id' => null]],
        'publish_mode' => 'draft',
    ], $overrides);
}

test('title body v2 rejects oversized urls on store preview and test send', function () {
    $v2 = ensureAnnouncementGeneralWhatsAppTemplate();
    $oversized = 'https://example.com/'.str_repeat('y', AnnouncementWhatsAppMessage::MAX_LENGTH);

    ['user' => $user, 'company' => $company] = makeAnnouncementWhatsAppLinkProfileFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'announcements.create',
        'announcements.update',
        'announcements.publish',
    ]);

    $this->mock(WhatsAppService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('normalizePhone')
            ->andReturnUsing(fn (string $phone): string => preg_replace('/\D+/', '', $phone) ?: '');
        $mock->shouldReceive('sendTemplate')->never();
    });

    $payload = announcementWhatsAppLinkPayload([
        'whatsapp_template_id' => $v2->id,
        'whatsapp_link' => $oversized,
    ]);

    $this->postJson(route('organization.announcements.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['whatsapp_link']);

    $this->postJson(route('organization.announcements.preview-channels'), [
        'title' => $payload['title'],
        'body_html' => $payload['body_html'],
        'category' => $payload['category'],
        'priority' => $payload['priority'],
        'channels' => ['whatsapp'],
        'whatsapp_message' => $payload['whatsapp_message'],
        'whatsapp_link' => $oversized,
        'whatsapp_template_id' => $v2->id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['whatsapp_link']);

    $this->postJson(route('organization.announcements.send-test'), [
        'title' => $payload['title'],
        'body_html' => $payload['body_html'],
        'category' => $payload['category'],
        'priority' => $payload['priority'],
        'channels' => ['whatsapp'],
        'whatsapp_message' => $payload['whatsapp_message'],
        'whatsapp_link' => $oversized,
        'whatsapp_template_id' => $v2->id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['whatsapp_link']);

    expect(Announcement::query()->where('company_id', $company->id)->count())->toBe(0);
});

test('legacy profile accepts urls over 500 characters and keeps url in parameter five', function () {
    $legacy = ensureAnnouncementWhatsAppTemplate();
    $longUrl = 'https://example.com/legacy/'.str_repeat('z', 520);

    expect(mb_strlen($longUrl))->toBeGreaterThan(AnnouncementWhatsAppMessage::MAX_LENGTH)
        ->and(mb_strlen($longUrl))->toBeLessThanOrEqual(2048);

    ['user' => $user, 'company' => $company] = makeAnnouncementWhatsAppLinkProfileFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'announcements.create',
        'announcements.update',
        'announcements.publish',
    ]);

    $this->mock(WhatsAppService::class, function (MockInterface $mock) use ($longUrl): void {
        $mock->shouldReceive('normalizePhone')
            ->andReturnUsing(fn (string $phone): string => preg_replace('/\D+/', '', $phone) ?: '');
        $mock->shouldReceive('sendTemplate')
            ->once()
            ->withArgs(function (string $phone, string $templateName, string $languageCode, array $components) use ($longUrl): bool {
                $body = collect($components)->firstWhere('type', 'body');

                return is_array($body)
                    && ($body['parameters'][4]['text'] ?? null) === $longUrl;
            })
            ->andReturn(['success' => true, 'message_id' => 'wamid.legacy-long-url']);
    });

    $this->post(route('organization.announcements.store'), announcementWhatsAppLinkPayload([
        'whatsapp_template_id' => $legacy->id,
        'whatsapp_link' => $longUrl,
        'whatsapp_message' => 'Legacy summary stays separate from the URL parameter.',
    ]))->assertRedirect();

    $announcement = Announcement::query()->where('company_id', $company->id)->firstOrFail();

    expect($announcement->whatsapp_link)->toBe($longUrl)
        ->and($announcement->whatsapp_template_id)->toBe($legacy->id);

    $built = app(BuildAnnouncementWhatsAppContent::class)->handle($announcement);

    expect($built)->not->toBeNull()
        ->and($built['components'][0]['parameters'])->toHaveCount(5)
        ->and($built['components'][0]['parameters'][4]['text'])->toBe($longUrl)
        ->and(mb_strlen($built['components'][0]['parameters'][2]['text']))->toBeLessThanOrEqual(AnnouncementWhatsAppMessage::MAX_LENGTH);

    $this->postJson(route('organization.announcements.preview-channels'), [
        'title' => $announcement->title,
        'body_html' => $announcement->body_html,
        'category' => $announcement->category->value,
        'priority' => $announcement->priority->value,
        'channels' => ['whatsapp'],
        'whatsapp_message' => $announcement->whatsapp_message,
        'whatsapp_link' => $longUrl,
        'whatsapp_template_id' => $legacy->id,
    ])->assertOk()
        ->assertJsonPath('channel_previews.whatsapp.available', true)
        ->assertJsonPath('channel_previews.whatsapp.view_link', $longUrl);

    $this->postJson(route('organization.announcements.send-test'), [
        'title' => $announcement->title,
        'body_html' => $announcement->body_html,
        'category' => $announcement->category->value,
        'priority' => $announcement->priority->value,
        'channels' => ['whatsapp'],
        'whatsapp_message' => $announcement->whatsapp_message,
        'whatsapp_link' => $longUrl,
        'whatsapp_template_id' => $legacy->id,
    ])->assertOk()
        ->assertJsonPath('whatsapp.success', true);
});

test('null template selection keeps legacy link behavior without v2 body limit', function () {
    $legacy = ensureAnnouncementWhatsAppTemplate();
    ensureAnnouncementGeneralWhatsAppTemplate();
    $longUrl = 'https://example.com/historical/'.str_repeat('a', 510);

    ['user' => $user, 'company' => $company] = makeAnnouncementWhatsAppLinkProfileFixtures();

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Historical legacy announcement',
        'body_html' => '<p>Historical body.</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Draft,
        'channels' => ['whatsapp'],
        'whatsapp_message' => 'Historical legacy announcement.',
        'whatsapp_link' => $longUrl,
        'whatsapp_template_id' => null,
        'created_by' => $user->id,
    ]);
    $announcement->setRelation('company', $company);

    $built = app(BuildAnnouncementWhatsAppContent::class)->handle($announcement);

    expect($built)->not->toBeNull()
        ->and($built['template']->id)->toBe($legacy->id)
        ->and($built['components'][0]['parameters'][4]['text'])->toBe($longUrl);
});

test('title body v2 preserves complete url while shortening only the message', function () {
    $v2 = ensureAnnouncementGeneralWhatsAppTemplate();
    $url = 'https://example.com/announcements/'.str_repeat('b', 40);
    $message = str_repeat('M', 490);

    ['user' => $user, 'company' => $company] = makeAnnouncementWhatsAppLinkProfileFixtures();

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'V2 link preservation',
        'body_html' => '<p>Body</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Draft,
        'channels' => ['whatsapp'],
        'whatsapp_message' => $message,
        'whatsapp_link' => $url,
        'whatsapp_template_id' => $v2->id,
        'created_by' => $user->id,
    ]);
    $announcement->setRelation('company', $company);

    $built = app(BuildAnnouncementWhatsAppContent::class)->handle($announcement);
    $body = $built['components'][1]['parameters'][0]['text'];

    expect(mb_strlen($body))->toBeLessThanOrEqual(AnnouncementWhatsAppMessage::MAX_LENGTH)
        ->and($body)->toEndWith($url)
        ->and($body)->not->toBe($message.' '.$url);
});
