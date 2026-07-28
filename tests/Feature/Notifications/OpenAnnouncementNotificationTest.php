<?php

use App\Enums\AnnouncementChannel;
use App\Enums\AnnouncementDeliveryStatus;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\AnnouncementRecipient;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * @return array{companyA: Company, companyB: Company, owner: User, other: User, recipient: AnnouncementRecipient}
 */
function makeOpenAnnouncementFixtures(): array
{
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $makeCompany = function (string $prefix): Company {
        $code = $prefix.fake()->unique()->numerify('##');
        $country = Country::query()->create([
            'code' => $code,
            'name' => "{$prefix}land",
            'dial_code' => '+971',
            'is_active' => true,
        ]);
        $currency = Currency::query()->create([
            'code' => $code,
            'name' => "{$prefix} Currency",
            'symbol' => 'O$',
            'is_active' => true,
        ]);

        return Company::query()->create([
            'name' => "{$prefix} Co",
            'slug' => strtolower($prefix).'-'.fake()->unique()->numerify('####'),
            'working_days' => [1, 2, 3, 4, 5],
            'country_id' => $country->id,
            'currency_id' => $currency->id,
            'timezone' => 'Asia/Dubai',
            'payroll_cycle' => 'monthly',
            'status' => 'active',
        ]);
    };

    $companyA = $makeCompany('OA');
    $companyB = $makeCompany('OB');

    DB::table('company_user')->insert([
        [
            'company_id' => $companyA->id,
            'user_id' => $owner->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'company_id' => $companyB->id,
            'user_id' => $owner->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $employee = Employee::factory()->forCompany($companyB)->create([
        'status' => 'active',
        'user_id' => $owner->id,
        'name' => 'Owner Employee',
    ]);

    $announcement = Announcement::query()->create([
        'company_id' => $companyB->id,
        'title' => 'Open from push',
        'body_html' => '<p>Opened</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Published,
        'channels' => ['in_app'],
        'created_by' => $owner->id,
        'published_by' => $owner->id,
        'published_at' => now(),
    ]);

    $recipient = AnnouncementRecipient::query()->create([
        'company_id' => $companyB->id,
        'announcement_id' => $announcement->id,
        'employee_id' => $employee->id,
        'user_id' => $owner->id,
        'employee_name' => $employee->name,
        'public_token' => str_repeat('e', 48),
    ]);

    AnnouncementDelivery::query()->create([
        'company_id' => $companyB->id,
        'announcement_recipient_id' => $recipient->id,
        'channel' => AnnouncementChannel::InApp,
        'status' => AnnouncementDeliveryStatus::Sent,
        'queued_at' => now(),
        'sent_at' => now(),
    ]);

    return compact('companyA', 'companyB', 'owner', 'other', 'recipient');
}

test('user can open their own pushed announcement and switch company', function () {
    ['companyA' => $companyA, 'companyB' => $companyB, 'owner' => $owner, 'recipient' => $recipient] = makeOpenAnnouncementFixtures();

    $this->actingAs($owner)
        ->withSession(['current_company_id' => $companyA->id])
        ->get("/notifications/announcements/{$recipient->id}/open")
        ->assertRedirect(route('organization.announcements.inbox.show', $recipient));

    expect(session('current_company_id'))->toBe($companyB->id);

    $this->actingAs($owner)
        ->withSession(['current_company_id' => $companyB->id])
        ->get(route('organization.announcements.inbox.show', $recipient))
        ->assertOk();

    expect($recipient->fresh()->read_at)->not->toBeNull()
        ->and($recipient->deliveries()->where('channel', AnnouncementChannel::InApp)->first()?->status)
        ->toBe(AnnouncementDeliveryStatus::Read);
});

test('another user receives 404 when opening a pushed announcement', function () {
    ['other' => $other, 'recipient' => $recipient] = makeOpenAnnouncementFixtures();

    $this->actingAs($other)
        ->get("/notifications/announcements/{$recipient->id}/open")
        ->assertNotFound();
});

test('user without membership in the recipient company is rejected', function () {
    ['companyB' => $companyB, 'owner' => $owner, 'recipient' => $recipient] = makeOpenAnnouncementFixtures();

    DB::table('company_user')
        ->where('company_id', $companyB->id)
        ->where('user_id', $owner->id)
        ->delete();

    $owner->forceFill(['company_id' => null])->save();

    $this->actingAs($owner)
        ->get("/notifications/announcements/{$recipient->id}/open")
        ->assertForbidden();
});

test('guest is redirected to login when opening a pushed announcement', function () {
    ['recipient' => $recipient] = makeOpenAnnouncementFixtures();

    $this->get("/notifications/announcements/{$recipient->id}/open")
        ->assertRedirect();
});
