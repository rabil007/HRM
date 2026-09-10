<?php

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementPriority;
use App\Enums\AnnouncementStatus;
use App\Enums\AnnouncementWhatsAppPayloadProfile;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;
use App\Services\WhatsAppService;
use App\Support\Announcements\BuildAnnouncementWhatsAppContent;
use Mockery\MockInterface;

/**
 * @return array{user: User, company: Company, employee: Employee}
 */
function makeAnnouncementPayloadParityFixtures(): array
{
    $user = User::factory()->create([
        'email' => 'parity@company.test',
    ]);
    $code = 'PP'.fake()->unique()->numerify('##');
    $country = Country::query()->create([
        'code' => $code,
        'name' => 'Parityland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => $code,
        'name' => 'Parity Currency',
        'symbol' => 'P$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Parity Co',
        'slug' => 'parity-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $user->id,
        'work_email' => 'parity-work@company.test',
        'phone' => '+971501112233',
        'name' => 'Parity Employee',
    ]);

    return compact('user', 'company', 'employee');
}

/**
 * @return array<string, mixed>
 */
function announcementPayloadParityFields(int $templateId): array
{
    return [
        'title' => 'Promotion Launch',
        'body_html' => '<p>Please share our LinkedIn update with your network.</p>',
        'category' => 'general',
        'priority' => 'normal',
        'whatsapp_message' => 'Share our LinkedIn promotion with your network.',
        'whatsapp_link' => 'https://example.com/linkedin-promo',
        'whatsapp_template_id' => $templateId,
        'channels' => ['whatsapp'],
    ];
}

test('preview test send and production builder return identical v2 components', function () {
    $template = ensureAnnouncementTitleBodyWhatsAppTemplate();
    ['user' => $user, 'company' => $company] = makeAnnouncementPayloadParityFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'announcements.create',
        'announcements.update',
        'announcements.publish',
    ]);

    $fields = announcementPayloadParityFields($template->id);

    $announcement = new Announcement([
        'company_id' => $company->id,
        'title' => $fields['title'],
        'body_html' => $fields['body_html'],
        'category' => AnnouncementCategory::from($fields['category']),
        'priority' => AnnouncementPriority::from($fields['priority']),
        'status' => AnnouncementStatus::Draft,
        'channels' => $fields['channels'],
        'whatsapp_link' => $fields['whatsapp_link'],
        'whatsapp_message' => $fields['whatsapp_message'],
        'whatsapp_template_id' => $template->id,
    ]);
    $announcement->setRelation('company', $company);
    $announcement->setRelation('attachments', collect());

    $built = app(BuildAnnouncementWhatsAppContent::class)->handle($announcement, $template->id);

    expect($built)->not->toBeNull()
        ->and($built['profile'])->toBe(AnnouncementWhatsAppPayloadProfile::TitleBodyV2);

    $expectedComponents = $built['components'];
    $expectedPreview = $built['preview'];

    $this->postJson(route('organization.announcements.preview-channels'), $fields)
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('channel_previews.whatsapp.available', true)
        ->assertJsonPath('channel_previews.whatsapp.template_id', $template->id)
        ->assertJsonPath('channel_previews.whatsapp.payload_profile', AnnouncementWhatsAppPayloadProfile::TitleBodyV2->value)
        ->assertJsonPath('channel_previews.whatsapp.header_text', $expectedPreview['header_text'])
        ->assertJsonPath('channel_previews.whatsapp.body_text', $expectedPreview['body_text'])
        ->assertJsonPath('channel_previews.whatsapp.resolved_message', $expectedPreview['resolved_message'])
        ->assertJsonPath('channel_previews.whatsapp.view_link', $expectedPreview['view_link']);

    $capturedComponents = null;

    $this->mock(WhatsAppService::class, function (MockInterface $mock) use (&$capturedComponents, $template): void {
        $mock->shouldReceive('normalizePhone')
            ->andReturnUsing(fn (string $phone): string => preg_replace('/\D+/', '', $phone) ?: '');
        $mock->shouldReceive('sendTemplate')
            ->once()
            ->withArgs(function (
                string $phone,
                string $metaName,
                string $metaLanguage,
                array $components,
            ) use (&$capturedComponents, $template): bool {
                $capturedComponents = $components;

                return $phone === '971501112233'
                    && $metaName === $template->meta_name
                    && $metaLanguage === $template->meta_language;
            })
            ->andReturn(['success' => true, 'message_id' => 'wamid.parity']);
    });

    $this->postJson(route('organization.announcements.send-test'), $fields)
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('whatsapp.success', true);

    expect($capturedComponents)->toBe($expectedComponents)
        ->and($capturedComponents)->toHaveCount(2)
        ->and($capturedComponents[0]['type'])->toBe('header')
        ->and($capturedComponents[0]['parameters'][0]['text'])->toBe('Promotion Launch')
        ->and($capturedComponents[1]['type'])->toBe('body')
        ->and($capturedComponents[1]['parameters'][0]['text'])
        ->toBe('Share our LinkedIn promotion with your network. https://example.com/linkedin-promo');
});
