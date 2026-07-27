<?php

use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementDeliveryStatus;
use App\Enums\AnnouncementStatus;
use App\Enums\WhatsAppTemplateCategory;
use App\Enums\WhatsAppTemplateHeaderType;
use App\Jobs\DeliverAnnouncementWhatsAppJob;
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
use App\Support\Announcements\Actions\RefreshAnnouncementDeliveryStatus;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;

/**
 * @return array{user: User, company: Company}
 */
function makeWhatsAppLinkAnnouncementFixtures(): array
{
    $user = User::factory()->create();
    $code = 'WL'.fake()->unique()->numerify('##');
    $country = Country::query()->create([
        'code' => $code,
        'name' => 'WhatsApp Link Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => $code,
        'name' => 'WhatsApp Link Currency',
        'symbol' => 'W$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'WhatsApp Link Co',
        'slug' => 'whatsapp-link-'.fake()->unique()->numerify('####'),
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

test('creating announcement with whatsapp requires a custom view link', function () {
    ['user' => $user, 'company' => $company] = makeWhatsAppLinkAnnouncementFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'announcements.view',
        'announcements.create',
    ]);

    $this->post('/organization/announcements', [
        'title' => 'Needs link',
        'body_html' => '<p>Body</p>',
        'category' => 'general',
        'priority' => 'normal',
        'channels' => ['whatsapp'],
        'whatsapp_message' => 'Body summary',
        'audiences' => [['type' => 'all_employees', 'id' => null]],
        'publish_mode' => 'draft',
    ])->assertSessionHasErrors('whatsapp_link');
});

test('creating announcement with whatsapp requires a plain text summary', function () {
    ['user' => $user, 'company' => $company] = makeWhatsAppLinkAnnouncementFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'announcements.view',
        'announcements.create',
    ]);

    $this->post('/organization/announcements', [
        'title' => 'Needs summary',
        'body_html' => '<p>Full email announcement</p>',
        'category' => 'general',
        'priority' => 'normal',
        'channels' => ['whatsapp'],
        'whatsapp_link' => 'https://example.com/announcement',
        'audiences' => [['type' => 'all_employees', 'id' => null]],
        'publish_mode' => 'draft',
    ])->assertSessionHasErrors('whatsapp_message');
});

test('creating announcement persists custom whatsapp view link', function () {
    ['user' => $user, 'company' => $company] = makeWhatsAppLinkAnnouncementFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'announcements.view',
        'announcements.create',
    ]);

    $this->post('/organization/announcements', [
        'title' => 'Custom link notice',
        'body_html' => '<p>Body</p>',
        'category' => 'general',
        'priority' => 'normal',
        'channels' => ['whatsapp'],
        'whatsapp_message' => 'Open the updated handbook.',
        'whatsapp_link' => 'https://example.com/handbook.pdf',
        'audiences' => [['type' => 'all_employees', 'id' => null]],
        'publish_mode' => 'draft',
    ])->assertRedirect();

    $announcement = Announcement::query()->where('company_id', $company->id)->first();

    expect($announcement)->not->toBeNull()
        ->and($announcement->whatsapp_message)->toBe('Open the updated handbook.')
        ->and($announcement->whatsapp_link)->toBe('https://example.com/handbook.pdf');
});

test('whatsapp job sends the custom announcement view link', function () {
    $code = 'WJ'.fake()->unique()->numerify('##');
    $country = Country::query()->create([
        'code' => $code,
        'name' => 'Jobland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => $code,
        'name' => 'Job Currency',
        'symbol' => 'J$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Job Co',
        'slug' => 'job-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'phone' => '+971501234567',
        'name' => 'Link Employee',
    ]);
    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Custom link send',
        'body_html' => '<p>Please open the shared file</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Published,
        'channels' => ['whatsapp'],
        'whatsapp_link' => 'https://files.example.com/notice.pdf',
        'published_at' => now(),
    ]);
    $recipient = AnnouncementRecipient::query()->create([
        'company_id' => $company->id,
        'announcement_id' => $announcement->id,
        'employee_id' => $employee->id,
        'employee_name' => $employee->name,
        'phone' => '971501234567',
        'public_token' => str_repeat('l', 48),
    ]);
    $delivery = AnnouncementDelivery::query()->create([
        'company_id' => $company->id,
        'announcement_recipient_id' => $recipient->id,
        'channel' => AnnouncementChannel::WhatsApp,
        'status' => AnnouncementDeliveryStatus::Queued,
        'queued_at' => now(),
    ]);

    WhatsAppTemplate::query()->updateOrCreate(
        ['slug' => 'announcement'],
        [
            'label' => 'Announcement',
            'category' => WhatsAppTemplateCategory::General,
            'meta_name' => 'employee_announcement_notice',
            'meta_language' => 'en',
            'header_type' => WhatsAppTemplateHeaderType::None,
            'body_preview' => '{{1}} — {{2}}: {{3}}. Priority: {{4}}. Open: {{5}}',
            'is_default' => true,
            'enabled' => true,
            'sort_order' => 1,
        ],
    );

    $this->mock(WhatsAppService::class, function (MockInterface $mock): void {
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
                    && $metaName === 'employee_announcement_notice'
                    && count($parameters) === 5
                    && $parameters[4] === ['type' => 'text', 'text' => 'https://files.example.com/notice.pdf'];
            })
            ->andReturn([
                'success' => true,
                'message_id' => 'wamid.custom',
            ]);
    });

    (new DeliverAnnouncementWhatsAppJob($delivery->id))->handle(
        app(WhatsAppService::class),
        app(RefreshAnnouncementDeliveryStatus::class),
    );

    expect($delivery->fresh())
        ->status->toBe(AnnouncementDeliveryStatus::Sent)
        ->provider_reference->toBe('wamid.custom');
});
