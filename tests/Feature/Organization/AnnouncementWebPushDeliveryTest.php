<?php

use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementDeliveryStatus;
use App\Enums\AnnouncementStatus;
use App\Jobs\DeliverAnnouncementInAppJob;
use App\Jobs\DeliverAnnouncementWebPushJob;
use App\Models\Announcement;
use App\Models\AnnouncementAudience;
use App\Models\AnnouncementDelivery;
use App\Models\AnnouncementRecipient;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\AnnouncementWebPushNotification;
use App\Support\Announcements\Actions\RefreshAnnouncementDeliveryStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * @return array{user: User, company: Company}
 */
function makeWebPushAnnouncementFixtures(): array
{
    $user = User::factory()->create();
    $code = 'WP'.fake()->unique()->numerify('##');
    $country = Country::query()->create([
        'code' => $code,
        'name' => 'Pushland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => $code,
        'name' => 'Push Currency',
        'symbol' => 'W$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Push Co',
        'slug' => 'push-'.fake()->unique()->numerify('####'),
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

test('publishing an in-app announcement queues in-app and web push jobs without a push delivery row', function () {
    Queue::fake();
    ['user' => $user, 'company' => $company] = makeWebPushAnnouncementFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'announcements.view',
        'announcements.create',
        'announcements.publish',
    ]);

    $employeeUser = User::factory()->create();
    Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $employeeUser->id,
        'work_email' => 'push@example.test',
        'name' => 'Push Employee',
    ]);

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'In-app notice',
        'body_html' => '<p>Hello</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Draft,
        'channels' => ['in_app'],
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

    expect(AnnouncementDelivery::query()->count())->toBe(1)
        ->and(AnnouncementDelivery::query()->first()?->channel)->toBe(AnnouncementChannel::InApp);

    Queue::assertPushed(DeliverAnnouncementInAppJob::class);
    Queue::assertPushed(DeliverAnnouncementWebPushJob::class);
});

test('email-only announcement does not queue web push', function () {
    Queue::fake();
    ['user' => $user, 'company' => $company] = makeWebPushAnnouncementFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'announcements.view',
        'announcements.publish',
    ]);

    Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => User::factory()->create()->id,
        'work_email' => 'email-only@example.test',
        'name' => 'Email Employee',
    ]);

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Email only',
        'body_html' => '<p>Email</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Draft,
        'channels' => ['email'],
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

    Queue::assertNotPushed(DeliverAnnouncementWebPushJob::class);
});

test('whatsapp-only announcement does not queue web push', function () {
    Queue::fake();
    ['user' => $user, 'company' => $company] = makeWebPushAnnouncementFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, [
        'announcements.view',
        'announcements.publish',
    ]);

    Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'phone' => '+971501234567',
        'name' => 'WhatsApp Employee',
    ]);

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'WhatsApp only',
        'body_html' => '<p>WA</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Draft,
        'channels' => ['whatsapp'],
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

    Queue::assertNotPushed(DeliverAnnouncementWebPushJob::class);
});

test('user without push subscriptions still receives successful in-app delivery', function () {
    ['user' => $user, 'company' => $company] = makeWebPushAnnouncementFixtures();

    $employeeUser = User::factory()->create();
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $employeeUser->id,
        'name' => 'No Sub Employee',
    ]);

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Bell only',
        'body_html' => '<p>Bell</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Published,
        'channels' => ['in_app'],
        'created_by' => $user->id,
        'published_by' => $user->id,
        'published_at' => now(),
    ]);

    $recipient = AnnouncementRecipient::query()->create([
        'company_id' => $company->id,
        'announcement_id' => $announcement->id,
        'employee_id' => $employee->id,
        'user_id' => $employeeUser->id,
        'employee_name' => $employee->name,
        'public_token' => str_repeat('b', 48),
    ]);

    $delivery = AnnouncementDelivery::query()->create([
        'company_id' => $company->id,
        'announcement_recipient_id' => $recipient->id,
        'channel' => AnnouncementChannel::InApp,
        'status' => AnnouncementDeliveryStatus::Queued,
        'queued_at' => now(),
    ]);

    (new DeliverAnnouncementInAppJob($delivery->id))->handle(
        app(RefreshAnnouncementDeliveryStatus::class),
    );

    (new DeliverAnnouncementWebPushJob($recipient->id))->handle();

    expect($delivery->fresh()->status)->toBe(AnnouncementDeliveryStatus::Sent)
        ->and($announcement->fresh()->status)->toBe(AnnouncementStatus::Published);
});

test('push failure does not mark in-app delivery failed or announcement partially delivered', function () {
    ['user' => $user, 'company' => $company] = makeWebPushAnnouncementFixtures();
    $employeeUser = User::factory()->create();
    $employeeUser->updatePushSubscription(
        'https://push.example.test/failing',
        'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE',
        'tBHItJI5svbpez7KI4CCXg',
        'aesgcm',
    );

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $employeeUser->id,
        'name' => 'Fail Push Employee',
    ]);

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Independent push',
        'body_html' => '<p>Push</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Published,
        'channels' => ['in_app'],
        'created_by' => $user->id,
        'published_by' => $user->id,
        'published_at' => now(),
    ]);

    $recipient = AnnouncementRecipient::query()->create([
        'company_id' => $company->id,
        'announcement_id' => $announcement->id,
        'employee_id' => $employee->id,
        'user_id' => $employeeUser->id,
        'employee_name' => $employee->name,
        'public_token' => str_repeat('c', 48),
    ]);

    $delivery = AnnouncementDelivery::query()->create([
        'company_id' => $company->id,
        'announcement_recipient_id' => $recipient->id,
        'channel' => AnnouncementChannel::InApp,
        'status' => AnnouncementDeliveryStatus::Sent,
        'queued_at' => now(),
        'sent_at' => now(),
    ]);

    Notification::shouldReceive('send')
        ->once()
        ->andThrow(new RuntimeException('push boom'));

    (new DeliverAnnouncementWebPushJob($recipient->id))->handle();

    expect($delivery->fresh()->status)->toBe(AnnouncementDeliveryStatus::Sent)
        ->and($announcement->fresh()->status)->toBe(AnnouncementStatus::Published);
});

test('web push job notifies all subscriptions for the recipient user', function () {
    Notification::fake();

    ['user' => $user, 'company' => $company] = makeWebPushAnnouncementFixtures();
    $employeeUser = User::factory()->create();
    $employeeUser->updatePushSubscription(
        'https://push.example.test/device-1',
        'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE',
        'tBHItJI5svbpez7KI4CCXg',
        'aesgcm',
    );
    $employeeUser->updatePushSubscription(
        'https://push.example.test/device-2',
        'BNcRnejnsCWcu6BCNCiCyiQoXKnAJkOjvgBgzEUrvsSMesTXHsYELfY35xZjFcRp27YWPBMBcIvP1uvxS9Xn1gE',
        'tBHItJI5svbpez7KI4CCXg',
        'aesgcm',
    );

    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $employeeUser->id,
        'name' => 'Multi Device',
    ]);

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Multi device',
        'body_html' => '<p>Devices</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Published,
        'channels' => ['in_app'],
        'created_by' => $user->id,
        'published_by' => $user->id,
        'published_at' => now(),
    ]);

    $recipient = AnnouncementRecipient::query()->create([
        'company_id' => $company->id,
        'announcement_id' => $announcement->id,
        'employee_id' => $employee->id,
        'user_id' => $employeeUser->id,
        'employee_name' => $employee->name,
        'public_token' => str_repeat('d', 48),
    ]);

    (new DeliverAnnouncementWebPushJob($recipient->id))->handle();

    Notification::assertSentTo(
        $employeeUser,
        AnnouncementWebPushNotification::class,
    );

    expect($employeeUser->pushSubscriptions()->count())->toBe(2);
});

test('scheduled publishing queues web push when in-app is selected', function () {
    Queue::fake();
    ['user' => $user, 'company' => $company] = makeWebPushAnnouncementFixtures();

    $employeeUser = User::factory()->create();
    Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $employeeUser->id,
        'name' => 'Scheduled Employee',
    ]);

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Scheduled push',
        'body_html' => '<p>Later</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Scheduled,
        'channels' => ['in_app'],
        'scheduled_at' => now()->subMinute(),
        'created_by' => $user->id,
    ]);

    AnnouncementAudience::query()->create([
        'company_id' => $company->id,
        'announcement_id' => $announcement->id,
        'audience_type' => 'all_employees',
        'audience_id' => null,
    ]);

    $this->artisan('announcements:publish-scheduled')
        ->assertSuccessful();

    Queue::assertPushed(DeliverAnnouncementWebPushJob::class);
});
