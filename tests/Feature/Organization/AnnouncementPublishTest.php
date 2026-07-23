<?php

use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementDeliveryStatus;
use App\Enums\AnnouncementStatus;
use App\Enums\WhatsAppTemplateCategory;
use App\Enums\WhatsAppTemplateHeaderType;
use App\Jobs\DeliverAnnouncementEmailJob;
use App\Jobs\DeliverAnnouncementInAppJob;
use App\Jobs\DeliverAnnouncementWhatsAppJob;
use App\Mail\AnnouncementMail;
use App\Models\Announcement;
use App\Models\AnnouncementAudience;
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
use App\Support\Announcements\BuildAnnouncementEmailContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{user: User, company: Company}
 */
function makePublishAnnouncementFixtures(): array
{
    $user = User::factory()->create();
    $code = 'AP'.fake()->unique()->numerify('##');
    $country = Country::query()->create([
        'code' => $code,
        'name' => 'Publishland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => $code,
        'name' => 'Publish Currency',
        'symbol' => 'P$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Publish Co',
        'slug' => 'publish-'.fake()->unique()->numerify('####'),
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

    return ['user' => $user, 'company' => $company];
}

test('publishing snapshots recipients and queues channel jobs', function () {
    Queue::fake();
    ['user' => $user, 'company' => $company] = makePublishAnnouncementFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'announcements.view',
        'announcements.create',
        'announcements.publish',
    ]);

    $employeeUser = User::factory()->create(['email' => 'linked@example.test']);
    Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $employeeUser->id,
        'work_email' => 'work@example.test',
        'phone' => '+971501111111',
        'name' => 'Linked Employee',
    ]);

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Meeting',
        'body_html' => '<p>All hands meeting</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Draft,
        'channels' => ['in_app', 'email', 'whatsapp'],
        'created_by' => $user->id,
    ]);

    AnnouncementAudience::query()->create([
        'company_id' => $company->id,
        'announcement_id' => $announcement->id,
        'audience_type' => 'all_employees',
        'audience_id' => null,
    ]);

    $this->post("/organization/announcements/{$announcement->id}/publish")
        ->assertRedirect();

    $announcement->refresh();

    expect($announcement->status)->toBe(AnnouncementStatus::Published)
        ->and(AnnouncementRecipient::query()->where('announcement_id', $announcement->id)->count())->toBe(1)
        ->and(AnnouncementDelivery::query()->count())->toBe(3);

    Queue::assertPushed(DeliverAnnouncementInAppJob::class);
    Queue::assertPushed(DeliverAnnouncementEmailJob::class);
    Queue::assertPushed(DeliverAnnouncementWhatsAppJob::class);
});

test('email is queued individually and whatsapp failure does not block email', function () {
    Mail::fake();
    ['user' => $user, 'company' => $company] = makePublishAnnouncementFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'announcements.view',
        'announcements.publish',
        'announcements.retry',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'work_email' => 'solo@example.test',
        'phone' => '+971502222222',
        'name' => 'Solo Employee',
    ]);

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Policy update',
        'body_html' => '<p>Updated policy</p>',
        'category' => 'policy',
        'priority' => 'high',
        'status' => AnnouncementStatus::Published,
        'channels' => ['email', 'whatsapp'],
        'created_by' => $user->id,
        'published_by' => $user->id,
        'published_at' => now(),
    ]);

    $recipient = AnnouncementRecipient::query()->create([
        'company_id' => $company->id,
        'announcement_id' => $announcement->id,
        'employee_id' => $employee->id,
        'employee_name' => $employee->name,
        'email' => 'solo@example.test',
        'phone' => '971502222222',
        'public_token' => str_repeat('a', 48),
    ]);

    $emailDelivery = AnnouncementDelivery::query()->create([
        'company_id' => $company->id,
        'announcement_recipient_id' => $recipient->id,
        'channel' => AnnouncementChannel::Email,
        'status' => AnnouncementDeliveryStatus::Queued,
        'queued_at' => now(),
    ]);

    $whatsappDelivery = AnnouncementDelivery::query()->create([
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
            'meta_name' => 'announcement',
            'meta_language' => 'en',
            'header_type' => WhatsAppTemplateHeaderType::None,
            'body_preview' => 'Announcement',
            'is_default' => true,
            'enabled' => true,
            'sort_order' => 1,
        ],
    );

    $this->mock(WhatsAppService::class, function ($mock) {
        $mock->shouldReceive('sendTemplate')->andReturn([
            'success' => false,
            'message_id' => null,
        ]);
        $mock->shouldReceive('normalizePhone')->andReturnUsing(fn ($phone) => preg_replace('/\D+/', '', $phone));
    });

    (new DeliverAnnouncementEmailJob($emailDelivery->id))->handle(
        app(BuildAnnouncementEmailContent::class),
        app(RefreshAnnouncementDeliveryStatus::class),
    );

    (new DeliverAnnouncementWhatsAppJob($whatsappDelivery->id))->handle(
        app(WhatsAppService::class),
        app(RefreshAnnouncementDeliveryStatus::class),
    );

    Mail::assertSent(AnnouncementMail::class, function (AnnouncementMail $mail) {
        return $mail->hasTo('solo@example.test');
    });

    expect($emailDelivery->fresh()->status)->toBe(AnnouncementDeliveryStatus::Sent)
        ->and($whatsappDelivery->fresh()->status)->toBe(AnnouncementDeliveryStatus::Failed)
        ->and($announcement->fresh()->status)->toBe(AnnouncementStatus::PartiallyDelivered);
});

test('scheduled announcements can be cancelled', function () {
    ['user' => $user, 'company' => $company] = makePublishAnnouncementFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'announcements.view',
        'announcements.cancel',
    ]);

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Later',
        'body_html' => '<p>Later</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Scheduled,
        'channels' => ['in_app'],
        'scheduled_at' => now()->addDay(),
        'created_by' => $user->id,
    ]);

    $this->post("/organization/announcements/{$announcement->id}/cancel")
        ->assertRedirect();

    expect($announcement->fresh()->status)->toBe(AnnouncementStatus::Cancelled);
});

test('failed deliveries can be retried', function () {
    Queue::fake();
    ['user' => $user, 'company' => $company] = makePublishAnnouncementFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'announcements.view',
        'announcements.retry',
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'work_email' => 'retry@example.test',
    ]);

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Retry me',
        'body_html' => '<p>Retry</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::PartiallyDelivered,
        'channels' => ['email'],
        'created_by' => $user->id,
        'published_at' => now(),
    ]);

    $recipient = AnnouncementRecipient::query()->create([
        'company_id' => $company->id,
        'announcement_id' => $announcement->id,
        'employee_id' => $employee->id,
        'employee_name' => $employee->name,
        'email' => 'retry@example.test',
        'public_token' => str_repeat('b', 48),
    ]);

    AnnouncementDelivery::query()->create([
        'company_id' => $company->id,
        'announcement_recipient_id' => $recipient->id,
        'channel' => AnnouncementChannel::Email,
        'status' => AnnouncementDeliveryStatus::Failed,
        'failed_at' => now(),
        'failure_reason' => 'Email delivery failed.',
    ]);

    $this->post("/organization/announcements/{$announcement->id}/retry")
        ->assertRedirect();

    Queue::assertPushed(DeliverAnnouncementEmailJob::class);
});

test('public announcement view and acknowledge routes are removed', function () {
    ['company' => $company] = makePublishAnnouncementFixtures();

    $employee = Employee::factory()->forCompany($company)->create(['status' => 'active']);

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'No public view',
        'body_html' => '<p>Content stays in channels</p>',
        'category' => 'hr',
        'priority' => 'urgent',
        'status' => AnnouncementStatus::Published,
        'channels' => ['email'],
        'published_at' => now(),
    ]);

    AnnouncementRecipient::query()->create([
        'company_id' => $company->id,
        'announcement_id' => $announcement->id,
        'employee_id' => $employee->id,
        'employee_name' => $employee->name,
        'email' => 'ack@example.test',
        'public_token' => str_repeat('c', 48),
    ]);

    $this->get('/announcements/public/'.str_repeat('c', 48))
        ->assertNotFound();

    $this->post('/announcements/public/'.str_repeat('c', 48).'/acknowledge')
        ->assertNotFound();
});

test('inbox feed only includes announcements for the linked user', function () {
    ['user' => $admin, 'company' => $company] = makePublishAnnouncementFixtures();
    $employeeUser = User::factory()->create();
    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $employeeUser->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $employeeUser->id,
    ]);

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Bell item',
        'body_html' => '<p>Hello team</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Published,
        'channels' => ['in_app'],
        'published_at' => now(),
        'created_by' => $admin->id,
    ]);

    $recipient = AnnouncementRecipient::query()->create([
        'company_id' => $company->id,
        'announcement_id' => $announcement->id,
        'employee_id' => $employee->id,
        'user_id' => $employeeUser->id,
        'employee_name' => $employee->name,
        'public_token' => str_repeat('d', 48),
    ]);

    AnnouncementDelivery::query()->create([
        'company_id' => $company->id,
        'announcement_recipient_id' => $recipient->id,
        'channel' => AnnouncementChannel::InApp,
        'status' => AnnouncementDeliveryStatus::Sent,
        'sent_at' => now(),
    ]);

    $this->actingAs($employeeUser)
        ->getJson('/organization/announcements/inbox/feed')
        ->assertOk()
        ->assertJsonPath('unread_count', 1)
        ->assertJsonPath('items.0.title', 'Bell item');
});
