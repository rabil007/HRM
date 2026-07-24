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
use App\Models\WhatsAppTemplate;
use App\Services\WhatsAppService;
use App\Support\Announcements\Actions\RefreshAnnouncementDeliveryStatus;
use App\Support\Announcements\BuildAnnouncementPublicLinks;
use Mockery\MockInterface;

/**
 * @return array{company: Company, recipient: AnnouncementRecipient, delivery: AnnouncementDelivery}
 */
function makeWhatsAppAnnouncementDelivery(array $overrides = []): array
{
    $code = 'WA'.fake()->unique()->numerify('##');
    $country = Country::query()->create([
        'code' => $code,
        'name' => 'WhatsAppland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => $code,
        'name' => 'WhatsApp Currency',
        'symbol' => 'W$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'WhatsApp Co',
        'slug' => 'whatsapp-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'phone' => '+971509999999',
        'work_email' => 'wa@example.test',
        'name' => 'WA Employee',
    ]);

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Muster drill',
        'body_html' => '<p>Report to station B immediately</p>',
        'category' => 'safety',
        'priority' => 'urgent',
        'status' => AnnouncementStatus::Published,
        'channels' => ['whatsapp'],
        'published_at' => now(),
    ]);

    $recipient = AnnouncementRecipient::query()->create([
        'company_id' => $company->id,
        'announcement_id' => $announcement->id,
        'employee_id' => $employee->id,
        'employee_name' => $employee->name,
        'email' => 'wa@example.test',
        'phone' => array_key_exists('phone', $overrides) ? $overrides['phone'] : '971509999999',
        'public_token' => str_repeat('w', 48),
    ]);

    $delivery = AnnouncementDelivery::query()->create([
        'company_id' => $company->id,
        'announcement_recipient_id' => $recipient->id,
        'channel' => AnnouncementChannel::WhatsApp,
        'status' => $overrides['status'] ?? AnnouncementDeliveryStatus::Queued,
        'queued_at' => now(),
        'sent_at' => $overrides['sent_at'] ?? null,
    ]);

    WhatsAppTemplate::query()->updateOrCreate(
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

    return compact('company', 'recipient', 'delivery');
}

test('whatsapp job sends five body parameters with recipient public url', function () {
    ['recipient' => $recipient, 'delivery' => $delivery] = makeWhatsAppAnnouncementDelivery();
    $expectedUrl = app(BuildAnnouncementPublicLinks::class)->showUrl($recipient);

    $this->mock(WhatsAppService::class, function (MockInterface $mock) use ($expectedUrl): void {
        $mock->shouldReceive('sendTemplate')
            ->once()
            ->withArgs(function (
                string $phone,
                string $metaName,
                string $metaLanguage,
                array $components,
            ) use ($expectedUrl): bool {
                $parameters = $components[0]['parameters'] ?? [];

                return $phone === '971509999999'
                    && $metaName === 'announcement'
                    && $metaLanguage === 'en_US'
                    && count($parameters) === 5
                    && $parameters[0] === ['type' => 'text', 'text' => 'WhatsApp Co']
                    && $parameters[1] === ['type' => 'text', 'text' => 'Muster drill']
                    && $parameters[2] === ['type' => 'text', 'text' => 'Report to station B immediately']
                    && $parameters[3] === ['type' => 'text', 'text' => 'Urgent']
                    && $parameters[4] === ['type' => 'text', 'text' => $expectedUrl]
                    && ! str_contains($parameters[4]['text'], 'wa@example.test')
                    && ! str_contains($parameters[4]['text'], '971509999999');
            })
            ->andReturn([
                'success' => true,
                'message_id' => 'wamid.123',
            ]);
    });

    (new DeliverAnnouncementWhatsAppJob($delivery->id))->handle(
        app(WhatsAppService::class),
        app(RefreshAnnouncementDeliveryStatus::class),
        app(BuildAnnouncementPublicLinks::class),
    );

    expect($delivery->fresh())
        ->status->toBe(AnnouncementDeliveryStatus::Sent)
        ->provider_reference->toBe('wamid.123');
});

test('already successful whatsapp deliveries are not resent', function () {
    ['delivery' => $delivery] = makeWhatsAppAnnouncementDelivery([
        'status' => AnnouncementDeliveryStatus::Sent,
        'sent_at' => now(),
    ]);

    $this->mock(WhatsAppService::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('sendTemplate');
    });

    (new DeliverAnnouncementWhatsAppJob($delivery->id))->handle(
        app(WhatsAppService::class),
        app(RefreshAnnouncementDeliveryStatus::class),
        app(BuildAnnouncementPublicLinks::class),
    );

    expect($delivery->fresh()->status)->toBe(AnnouncementDeliveryStatus::Sent);
});

test('missing phone numbers remain skipped for whatsapp delivery', function () {
    ['delivery' => $delivery] = makeWhatsAppAnnouncementDelivery([
        'phone' => null,
    ]);

    AnnouncementRecipient::query()->whereKey($delivery->announcement_recipient_id)->update([
        'phone' => null,
    ]);

    $this->mock(WhatsAppService::class, function (MockInterface $mock): void {
        $mock->shouldNotReceive('sendTemplate');
    });

    (new DeliverAnnouncementWhatsAppJob($delivery->id))->handle(
        app(WhatsAppService::class),
        app(RefreshAnnouncementDeliveryStatus::class),
        app(BuildAnnouncementPublicLinks::class),
    );

    expect($delivery->fresh()->status)->toBe(AnnouncementDeliveryStatus::Skipped);
});
