<?php

use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementDeliveryStatus;
use App\Enums\AnnouncementStatus;
use App\Enums\PlatformAccess;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\AnnouncementRecipient;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @return array{user: User, company: Company}
 */
function makeInboxAnnouncementCompany(): array
{
    $user = User::factory()->create();
    $code = 'IR'.fake()->unique()->numerify('##');
    $country = Country::query()->create([
        'code' => $code,
        'name' => 'Inboxland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => $code,
        'name' => 'Inbox Currency',
        'symbol' => 'I$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Inbox Co',
        'slug' => 'inbox-'.fake()->unique()->numerify('####'),
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

function makeInboxRecipient(Company $company, User $user, string $title = 'Inbox notice'): AnnouncementRecipient
{
    $employee = Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $user->id,
    ]);

    $announcement = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => $title,
        'body_html' => '<p>Body</p>',
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
        'user_id' => $user->id,
        'employee_name' => $employee->name,
        'public_token' => Str::random(48),
    ]);

    AnnouncementDelivery::query()->create([
        'company_id' => $company->id,
        'announcement_recipient_id' => $recipient->id,
        'channel' => AnnouncementChannel::InApp,
        'status' => AnnouncementDeliveryStatus::Sent,
        'queued_at' => now(),
        'sent_at' => now(),
    ]);

    return $recipient;
}

test('user can mark their own announcement recipient as read', function () {
    ['user' => $user, 'company' => $company] = makeInboxAnnouncementCompany();
    $recipient = makeInboxRecipient($company, $user);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.announcements.inbox.read', $recipient))
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect($recipient->fresh()->read_at)->not->toBeNull();
});

test('marking an already-read announcement recipient is idempotent', function () {
    ['user' => $user, 'company' => $company] = makeInboxAnnouncementCompany();
    $recipient = makeInboxRecipient($company, $user);
    $readAt = now()->subHour();
    $recipient->update(['read_at' => $readAt]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.announcements.inbox.read', $recipient))
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect($recipient->fresh()->read_at?->toDateTimeString())->toBe($readAt->toDateTimeString());
});

test('user cannot mark another users announcement recipient as read', function () {
    ['user' => $owner, 'company' => $company] = makeInboxAnnouncementCompany();
    $coworker = User::factory()->create();
    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $coworker->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $recipient = makeInboxRecipient($company, $coworker, 'Coworker notice');

    $this->actingAs($owner)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.announcements.inbox.read', $recipient))
        ->assertNotFound();

    expect($recipient->fresh()->read_at)->toBeNull();
});

test('company A cannot mark a company B announcement recipient as read', function () {
    ['user' => $userA, 'company' => $companyA] = makeInboxAnnouncementCompany();
    ['user' => $userB, 'company' => $companyB] = makeInboxAnnouncementCompany();
    $recipientB = makeInboxRecipient($companyB, $userB, 'Foreign notice');

    $this->actingAs($userA)
        ->withSession(['current_company_id' => $companyA->id])
        ->postJson(route('organization.announcements.inbox.read', $recipientB), [
            'company_id' => $companyB->id,
        ])
        ->assertNotFound();

    expect($recipientB->fresh()->read_at)->toBeNull();
});

test('forged company_id does not allow marking a foreign announcement recipient', function () {
    ['user' => $user, 'company' => $company] = makeInboxAnnouncementCompany();
    $ownRecipient = makeInboxRecipient($company, $user);
    ['company' => $companyB] = makeInboxAnnouncementCompany();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->postJson(route('organization.announcements.inbox.read', $ownRecipient), [
            'company_id' => $companyB->id,
        ])
        ->assertOk();

    expect($ownRecipient->fresh()->read_at)->not->toBeNull();
});

test('platform access does not bypass announcement recipient ownership', function () {
    ['user' => $owner, 'company' => $company] = makeInboxAnnouncementCompany();
    $recipient = makeInboxRecipient($company, $owner);
    $platformUser = User::factory()->create(['company_id' => null]);
    $platformUser->forceFill(['platform_access' => PlatformAccess::Manage])->save();

    $this->actingAs($platformUser)
        ->withSession([])
        ->postJson(route('organization.announcements.inbox.read', $recipient))
        ->assertNotFound();

    expect($recipient->fresh()->read_at)->toBeNull();
});
