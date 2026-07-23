<?php

use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @return array{user: User, company: Company}
 */
function makeAnnouncementFixtures(): array
{
    $user = User::factory()->create();
    $code = 'AN'.fake()->unique()->numerify('##');
    $country = Country::query()->create([
        'code' => $code,
        'name' => 'Announcementland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => $code,
        'name' => 'Announcement Currency',
        'symbol' => 'A$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Announcement Co',
        'slug' => 'announcement-'.fake()->unique()->numerify('####'),
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

/**
 * @return list<string>
 */
function announcementPermissions(): array
{
    return [
        'announcements.view',
        'announcements.create',
        'announcements.update',
        'announcements.publish',
        'announcements.cancel',
        'announcements.retry',
        'announcements.download_attachments',
    ];
}

test('guests cannot access announcements', function () {
    $this->get('/organization/announcements')->assertRedirect(route('login'));
});

test('users without permission cannot view announcements', function () {
    ['user' => $user, 'company' => $company] = makeAnnouncementFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, ['branches.view']);

    $this->get('/organization/announcements')->assertForbidden();
});

test('authorized users can view announcements index', function () {
    ['user' => $user, 'company' => $company] = makeAnnouncementFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, announcementPermissions());

    $this->get('/organization/announcements')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/announcements/index')
            ->has('announcements')
            ->where('can.view', true)
        );
});

test('authorized users can create a draft announcement', function () {
    ['user' => $user, 'company' => $company] = makeAnnouncementFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, announcementPermissions());

    Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'work_email' => 'worker@example.test',
    ]);

    $this->post('/organization/announcements', [
        'title' => 'Safety briefing',
        'body_html' => '<p>Please attend the safety briefing.</p>',
        'category' => 'safety',
        'priority' => 'high',
        'channels' => ['in_app', 'email'],
        'audiences' => [['type' => 'all_employees', 'id' => null]],
        'publish_mode' => 'draft',
    ])->assertRedirect();

    $announcement = Announcement::query()->where('company_id', $company->id)->first();

    expect($announcement)->not->toBeNull()
        ->and($announcement->status)->toBe(AnnouncementStatus::Draft)
        ->and($announcement->title)->toBe('Safety briefing');
});

test('draft announcements can be deleted and published ones cannot', function () {
    ['user' => $user, 'company' => $company] = makeAnnouncementFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, announcementPermissions());

    $draft = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Draft',
        'body_html' => '<p>Draft</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Draft,
        'channels' => ['in_app'],
        'created_by' => $user->id,
    ]);

    $published = Announcement::query()->create([
        'company_id' => $company->id,
        'title' => 'Published',
        'body_html' => '<p>Published</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Published,
        'channels' => ['in_app'],
        'created_by' => $user->id,
        'published_at' => now(),
    ]);

    $this->delete("/organization/announcements/{$draft->id}")
        ->assertRedirect('/organization/announcements');

    expect(Announcement::query()->find($draft->id))->toBeNull();

    $this->from("/organization/announcements/{$published->id}")
        ->delete("/organization/announcements/{$published->id}")
        ->assertSessionHasErrors('status');

    expect(Announcement::query()->find($published->id))->not->toBeNull();
});

test('cross-company announcements are not accessible', function () {
    ['user' => $user, 'company' => $company] = makeAnnouncementFixtures();
    $other = makeAnnouncementFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, announcementPermissions());

    $announcement = Announcement::query()->create([
        'company_id' => $other['company']->id,
        'title' => 'Other company',
        'body_html' => '<p>Secret</p>',
        'category' => 'general',
        'priority' => 'normal',
        'status' => AnnouncementStatus::Draft,
        'channels' => ['in_app'],
        'created_by' => $other['user']->id,
    ]);

    $this->get("/organization/announcements/{$announcement->id}")->assertNotFound();
});

test('preview recipients returns channel availability counts', function () {
    ['user' => $user, 'company' => $company] = makeAnnouncementFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, announcementPermissions());

    $linkedUser = User::factory()->create();
    Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'user_id' => $linkedUser->id,
        'work_email' => 'a@example.test',
        'phone' => '+971501234567',
    ]);
    Employee::factory()->forCompany($company)->create([
        'status' => 'active',
        'work_email' => null,
        'personal_email' => null,
        'phone' => null,
    ]);

    $this->postJson('/organization/announcements/preview-recipients', [
        'channels' => ['in_app', 'email', 'whatsapp'],
        'audiences' => [['type' => 'all_employees', 'id' => null]],
    ])->assertOk()
        ->assertJsonPath('selected_employees', 2)
        ->assertJsonPath('in_app_available', 1)
        ->assertJsonPath('email_available', 1)
        ->assertJsonPath('missing_email', 1)
        ->assertJsonPath('missing_phone', 1);
});

test('selecting every employee as specific audience resolves as all employees', function () {
    ['user' => $user, 'company' => $company] = makeAnnouncementFixtures();
    $this->actingAs($user);
    grantCompanyPermissions($user, $company, announcementPermissions());

    $employees = Employee::factory()->forCompany($company)->count(3)->create([
        'status' => 'active',
        'work_email' => 'worker@example.test',
    ]);

    $this->postJson('/organization/announcements/preview-recipients', [
        'channels' => ['email'],
        'audiences' => $employees->map(fn ($employee) => [
            'type' => 'employee',
            'id' => $employee->id,
        ])->values()->all(),
    ])->assertOk()
        ->assertJsonPath('selected_employees', 3);

    $this->post('/organization/announcements', [
        'title' => 'Everyone',
        'body_html' => '<p>All hands</p>',
        'category' => 'general',
        'priority' => 'normal',
        'channels' => ['email'],
        'audiences' => $employees->map(fn ($employee) => [
            'type' => 'employee',
            'id' => $employee->id,
        ])->values()->all(),
        'publish_mode' => 'draft',
    ])->assertRedirect();

    $announcement = Announcement::query()->where('company_id', $company->id)->first();

    expect($announcement)->not->toBeNull();
    expect($announcement->audiences)->toHaveCount(1)
        ->and($announcement->audiences->first()->audience_type->value)->toBe('all_employees');
});
