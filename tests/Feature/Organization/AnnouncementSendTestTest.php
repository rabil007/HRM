<?php

use App\Enums\AnnouncementStatus;
use App\Enums\WhatsAppTemplateCategory;
use App\Enums\WhatsAppTemplateHeaderType;
use App\Jobs\DeliverAnnouncementWebPushJob;
use App\Mail\AnnouncementMail;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\AnnouncementRecipient;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;
use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppService;
use App\Support\Announcements\BuildAnnouncementEmailContent;
use App\Support\Announcements\MaskAnnouncementContact;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;

/**
 * @return array{user: User, company: Company, employee: Employee}
 */
function makeAnnouncementSendTestFixtures(array $employeeOverrides = []): array
{
    $user = User::factory()->create([
        'email' => 'publisher@company.test',
    ]);
    $code = 'ST'.fake()->unique()->numerify('##');
    $country = Country::query()->create([
        'code' => $code,
        'name' => 'SendTestland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => $code,
        'name' => 'Send Test Currency',
        'symbol' => 'S$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Send Test Co',
        'slug' => 'send-test-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->create(array_merge([
        'status' => 'active',
        'user_id' => $user->id,
        'work_email' => 'work@company.test',
        'phone' => '+971501234567',
        'name' => 'Publisher Employee',
    ], $employeeOverrides));

    return compact('user', 'company', 'employee');
}

function ensureAnnouncementWhatsAppTemplate(): WhatsAppTemplate
{
    return WhatsAppTemplate::query()->updateOrCreate(
        ['slug' => 'announcement'],
        [
            'label' => 'Announcement',
            'category' => WhatsAppTemplateCategory::General,
            'meta_name' => 'announcement',
            'meta_language' => 'en_US',
            'header_type' => WhatsAppTemplateHeaderType::None,
            'body_preview' => '{{1}} — {{2}}: {{3}}. Priority: {{4}}. Open: {{5}}',
            'is_default' => true,
            'enabled' => true,
            'sort_order' => 1,
        ],
    );
}

/**
 * @return array<string, mixed>
 */
function announcementSendTestPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Stay Connected with OMS on LinkedIn',
        'body_html' => '<p>Please follow our company page.</p>',
        'category' => 'general',
        'priority' => 'normal',
        'whatsapp_link' => 'https://example.com/notice',
        'channels' => ['email', 'whatsapp'],
    ], $overrides);
}

test('authorized publisher can send email and whatsapp tests to own destinations', function () {
    Mail::fake();
    Queue::fake();
    ensureAnnouncementWhatsAppTemplate();
    ['user' => $user, 'company' => $company] = makeAnnouncementSendTestFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['announcements.publish']);

    $this->mock(WhatsAppService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('normalizePhone')
            ->andReturnUsing(fn (string $phone): string => preg_replace('/\D+/', '', $phone) ?: '');
        $mock->shouldReceive('sendTemplate')
            ->once()
            ->withArgs(function (
                string $phone,
                string $metaName,
                string $metaLanguage,
                array $components,
            ): bool {
                $parameters = $components[0]['parameters'] ?? [];

                return $phone === '971501234567'
                    && $metaName === 'announcement'
                    && $metaLanguage === 'en_US'
                    && count($parameters) === 5
                    && $parameters[1]['text'] === 'Stay Connected with OMS on LinkedIn'
                    && $parameters[4]['text'] === 'https://example.com/notice';
            })
            ->andReturn(['success' => true, 'message_id' => 'wamid.test']);
    });

    $response = $this->postJson(route('organization.announcements.send-test'), announcementSendTestPayload())
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('email.success', true)
        ->assertJsonPath('whatsapp.success', true)
        ->assertJsonPath('destinations.email.masked', MaskAnnouncementContact::email('work@company.test'))
        ->assertJsonPath('destinations.whatsapp.masked', MaskAnnouncementContact::phone('971501234567'));

    expect($response->json('destinations.email'))->not->toHaveKey('value')
        ->and($response->json('destinations.whatsapp'))->not->toHaveKey('value');

    Mail::assertSent(AnnouncementMail::class, function (AnnouncementMail $mail): bool {
        return str_starts_with($mail->subjectLine, '[TEST] ')
            && str_contains($mail->subjectLine, 'Stay Connected with OMS on LinkedIn')
            && str_contains($mail->bodyHtml, 'Please follow our company page.');
    });

    expect(AnnouncementRecipient::query()->count())->toBe(0)
        ->and(AnnouncementDelivery::query()->count())->toBe(0);

    Queue::assertNotPushed(DeliverAnnouncementWebPushJob::class);

    expect(Activity::query()->where('event', 'announcement_test_sent')->count())->toBe(1);
});

test('users without announcements.publish cannot send tests', function () {
    ['user' => $user, 'company' => $company] = makeAnnouncementSendTestFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['announcements.create', 'announcements.view']);

    $this->postJson(route('organization.announcements.send-test'), announcementSendTestPayload())
        ->assertForbidden();
});

test('client supplied destinations and company ids are ignored', function () {
    Mail::fake();
    ensureAnnouncementWhatsAppTemplate();
    ['user' => $user, 'company' => $company] = makeAnnouncementSendTestFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['announcements.publish']);

    $this->mock(WhatsAppService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('normalizePhone')
            ->andReturnUsing(fn (string $phone): string => preg_replace('/\D+/', '', $phone) ?: '');
        $mock->shouldReceive('sendTemplate')
            ->once()
            ->withArgs(fn (string $phone): bool => $phone === '971501234567')
            ->andReturn(['success' => true, 'message_id' => 'wamid.test']);
    });

    $this->postJson(route('organization.announcements.send-test'), announcementSendTestPayload([
        'email' => 'attacker@evil.test',
        'phone' => '+971509999999',
        'company_id' => 999999,
        'user_id' => 999999,
        'employee_id' => 999999,
        'channels' => ['email', 'whatsapp'],
    ]))->assertOk()
        ->assertJsonPath('email.success', true)
        ->assertJsonPath('whatsapp.success', true);

    Mail::assertSent(AnnouncementMail::class, function (AnnouncementMail $mail): bool {
        return $mail->hasTo('work@company.test')
            && ! $mail->hasTo('attacker@evil.test');
    });
});

test('cross company employee contacts cannot be used for test send', function () {
    Mail::fake();
    ['user' => $user, 'company' => $company] = makeAnnouncementSendTestFixtures([
        'work_email' => null,
        'personal_email' => null,
        'phone' => null,
    ]);

    $otherCode = 'OX'.fake()->unique()->numerify('##');
    $otherCountry = Country::query()->create([
        'code' => $otherCode,
        'name' => 'Otherland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $otherCurrency = Currency::query()->create([
        'code' => $otherCode,
        'name' => 'Other Currency',
        'symbol' => 'O$',
        'is_active' => true,
    ]);
    $otherCompany = Company::query()->create([
        'name' => 'Other Co',
        'slug' => 'other-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $otherCountry->id,
        'currency_id' => $otherCurrency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    Employee::factory()->forCompany($otherCompany)->create([
        'status' => 'active',
        'user_id' => $user->id,
        'work_email' => 'other-company@evil.test',
        'phone' => '+971509999999',
    ]);

    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['announcements.publish']);

    $this->mock(WhatsAppService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('normalizePhone')
            ->andReturnUsing(fn (string $phone): string => preg_replace('/\D+/', '', $phone) ?: '');
        $mock->shouldReceive('sendTemplate')->never();
    });

    // Falls back to authenticated user email for email; WhatsApp unavailable without company employee phone.
    $this->postJson(route('organization.announcements.send-test'), announcementSendTestPayload([
        'channels' => ['email', 'whatsapp'],
    ]))->assertOk()
        ->assertJsonPath('email.success', true)
        ->assertJsonPath('whatsapp.attempted', false);

    Mail::assertSent(AnnouncementMail::class, fn (AnnouncementMail $mail): bool => $mail->hasTo('publisher@company.test'));
    Mail::assertNotSent(AnnouncementMail::class, fn (AnnouncementMail $mail): bool => $mail->hasTo('other-company@evil.test'));
});

test('only requested channels are sent', function () {
    Mail::fake();
    ensureAnnouncementWhatsAppTemplate();
    ['user' => $user, 'company' => $company] = makeAnnouncementSendTestFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['announcements.publish']);

    $this->mock(WhatsAppService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('normalizePhone')
            ->andReturnUsing(fn (string $phone): string => preg_replace('/\D+/', '', $phone) ?: '');
        $mock->shouldReceive('sendTemplate')->never();
    });

    $this->postJson(route('organization.announcements.send-test'), announcementSendTestPayload([
        'channels' => ['email'],
    ]))->assertOk()
        ->assertJsonPath('email.success', true)
        ->assertJsonPath('whatsapp', null);

    Mail::assertSent(AnnouncementMail::class);
});

test('in_app channel is rejected for test send', function () {
    ['user' => $user, 'company' => $company] = makeAnnouncementSendTestFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['announcements.publish']);

    $this->postJson(route('organization.announcements.send-test'), announcementSendTestPayload([
        'channels' => ['in_app'],
    ]))->assertUnprocessable();
});

test('raw html is sanitized before email test send', function () {
    Mail::fake();
    ['user' => $user, 'company' => $company] = makeAnnouncementSendTestFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['announcements.publish']);

    $this->mock(WhatsAppService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('normalizePhone')
            ->andReturnUsing(fn (string $phone): string => preg_replace('/\D+/', '', $phone) ?: '');
    });

    $this->postJson(route('organization.announcements.send-test'), announcementSendTestPayload([
        'body_html' => '<p>Hello</p><script>alert(1)</script>',
        'channels' => ['email'],
    ]))->assertOk();

    Mail::assertSent(AnnouncementMail::class, function (AnnouncementMail $mail): bool {
        return str_contains($mail->bodyHtml, 'Hello')
            && ! str_contains($mail->bodyHtml, '<script>');
    });
});

test('draft announcement state stays unchanged after test send', function () {
    Mail::fake();
    ['user' => $user, 'company' => $company] = makeAnnouncementSendTestFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['announcements.publish']);

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Draft title',
        'body_html' => '<p>Draft body</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Draft,
        'channels' => ['email'],
        'created_by' => $user->id,
        'scheduled_at' => null,
        'published_at' => null,
        'published_by' => null,
    ]);

    $this->mock(WhatsAppService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('normalizePhone')
            ->andReturnUsing(fn (string $phone): string => preg_replace('/\D+/', '', $phone) ?: '');
    });

    $this->postJson(route('organization.announcements.send-test'), announcementSendTestPayload([
        'title' => 'Updated draft content for test',
        'channels' => ['email'],
        'announcement_id' => $announcement->id,
    ]))->assertOk();

    $fresh = $announcement->fresh();
    expect($fresh)
        ->status->toBe(AnnouncementStatus::Draft)
        ->title->toBe('Draft title')
        ->published_at->toBeNull()
        ->published_by->toBeNull()
        ->scheduled_at->toBeNull()
        ->and(AnnouncementRecipient::query()->where('announcement_id', $announcement->id)->count())->toBe(0)
        ->and(AnnouncementDelivery::query()->count())->toBe(0);
});

test('scheduled announcement state stays unchanged after test send', function () {
    Mail::fake();
    ['user' => $user, 'company' => $company] = makeAnnouncementSendTestFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['announcements.publish']);

    $scheduledAt = now()->addDay()->seconds(0);
    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Scheduled title',
        'body_html' => '<p>Scheduled body</p>',
        'category' => 'general',
        'priority' => 'high',
        'status' => AnnouncementStatus::Scheduled,
        'channels' => ['email'],
        'created_by' => $user->id,
        'scheduled_at' => $scheduledAt,
        'published_at' => null,
        'published_by' => null,
    ]);

    $this->mock(WhatsAppService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('normalizePhone')
            ->andReturnUsing(fn (string $phone): string => preg_replace('/\D+/', '', $phone) ?: '');
    });

    $this->postJson(route('organization.announcements.send-test'), announcementSendTestPayload([
        'channels' => ['email'],
        'announcement_id' => $announcement->id,
    ]))->assertOk();

    $fresh = $announcement->fresh();
    expect($fresh)
        ->status->toBe(AnnouncementStatus::Scheduled)
        ->published_at->toBeNull()
        ->published_by->toBeNull()
        ->and($fresh->scheduled_at?->toIso8601String())->toBe($scheduledAt->toIso8601String());
});

test('email test uses BuildAnnouncementEmailContent renderer', function () {
    Mail::fake();
    ['user' => $user, 'company' => $company] = makeAnnouncementSendTestFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['announcements.publish']);

    $this->mock(WhatsAppService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('normalizePhone')
            ->andReturnUsing(fn (string $phone): string => preg_replace('/\D+/', '', $phone) ?: '');
    });

    $this->postJson(route('organization.announcements.send-test'), announcementSendTestPayload([
        'channels' => ['email'],
    ]))->assertOk();

    $announcement = new Announcement([
        'company_id' => $company->id,
        'title' => 'Stay Connected with OMS on LinkedIn',
        'body_html' => '<p>Please follow our company page.</p>',
        'category' => 'general',
        'priority' => 'normal',
    ]);
    $announcement->setRelation('company', $company);
    $announcement->setRelation('attachments', collect());
    $content = app(BuildAnnouncementEmailContent::class)->preview($announcement);

    Mail::assertSent(AnnouncementMail::class, function (AnnouncementMail $mail) use ($content): bool {
        return $mail->subjectLine === '[TEST] '.$content['subject']
            && $mail->bodyHtml === $content['html'];
    });
});

test('missing whatsapp phone still allows email test', function () {
    Mail::fake();
    ['user' => $user, 'company' => $company] = makeAnnouncementSendTestFixtures([
        'phone' => null,
    ]);
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['announcements.publish']);

    $this->mock(WhatsAppService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('normalizePhone')
            ->andReturnUsing(fn (string $phone): string => preg_replace('/\D+/', '', $phone) ?: '');
        $mock->shouldReceive('sendTemplate')->never();
    });

    $this->postJson(route('organization.announcements.send-test'), announcementSendTestPayload())
        ->assertOk()
        ->assertJsonPath('email.success', true)
        ->assertJsonPath('whatsapp.attempted', false)
        ->assertJsonPath('whatsapp.message', 'No valid employee phone is linked to your account.');
});

test('send test endpoint is rate limited', function () {
    Mail::fake();
    ['user' => $user, 'company' => $company] = makeAnnouncementSendTestFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['announcements.publish']);

    $this->mock(WhatsAppService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('normalizePhone')
            ->andReturnUsing(fn (string $phone): string => preg_replace('/\D+/', '', $phone) ?: '');
    });

    for ($i = 0; $i < 5; $i++) {
        $this->postJson(route('organization.announcements.send-test'), announcementSendTestPayload([
            'channels' => ['email'],
        ]))->assertOk();
    }

    $this->postJson(route('organization.announcements.send-test'), announcementSendTestPayload([
        'channels' => ['email'],
    ]))->assertTooManyRequests();
});
