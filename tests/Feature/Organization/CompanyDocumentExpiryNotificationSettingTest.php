<?php

use App\Models\Company;
use App\Models\CompanyDocumentExpiryNotificationSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

/** @return array{company: Company, user: User} */
function notificationSettingContext(array $permissions = []): array
{
    $fixtures = makeDocumentFixtures();
    $user = User::factory()->create(['company_id' => $fixtures['company']->id]);

    if ($permissions !== []) {
        grantCompanyPermissions($user, $fixtures['company'], $permissions);
    }

    return ['company' => $fixtures['company'], 'user' => $user];
}

function activeMemberOf(Company $company): User
{
    $user = User::factory()->create();
    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $user->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

// ─────────────────────────────────────────────────────────────────────────────
// Authorization tests
// ─────────────────────────────────────────────────────────────────────────────

test('guests cannot access company documents index', function () {
    $fixtures = makeDocumentFixtures();

    $this->get(route('organization.companies.documents.index', $fixtures['company']))
        ->assertRedirect(route('login'));
});

test('company documents index is accessible to members with view permission', function () {
    ['company' => $company, 'user' => $user] = notificationSettingContext([
        'company_documents.view',
    ]);

    $this->actingAs($user)
        ->get(route('organization.companies.documents.index', $company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/company-documents')
            ->has('can')
            ->where('can.manage_notifications', false)
            ->where('notification_setting', null)
            ->where('company_users', [])
        );
});

test('authorized user can see notification setting props on index page', function () {
    ['company' => $company, 'user' => $user] = notificationSettingContext([
        'company_documents.view',
        'company_documents.manage_notifications',
    ]);

    $this->actingAs($user)
        ->get(route('organization.companies.documents.index', $company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('can.manage_notifications', true)
            ->where('notification_setting.enabled', false)
            ->where('notification_setting.to_recipients', [])
            ->where('notification_setting.cc_recipients', [])
        );
});

test('unauthorized user cannot update notification settings', function () {
    ['company' => $company, 'user' => $user] = notificationSettingContext([
        'company_documents.view',
    ]);
    $recipient = activeMemberOf($company);

    $this->actingAs($user)
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $company), [
            'enabled' => true,
            'to_user_ids' => [$recipient->id],
            'cc_user_ids' => [],
        ])
        ->assertForbidden();

    expect(CompanyDocumentExpiryNotificationSetting::query()->count())->toBe(0);
});

test('non-member cannot update notification settings', function () {
    $fixtures = makeDocumentFixtures();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $fixtures['company']), [
            'enabled' => true,
            'to_user_ids' => [],
            'cc_user_ids' => [],
        ])
        ->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// Settings CRUD
// ─────────────────────────────────────────────────────────────────────────────

test('authorized user can enable notifications with valid recipients', function () {
    ['company' => $company, 'user' => $user] = notificationSettingContext([
        'company_documents.view',
        'company_documents.manage_notifications',
    ]);
    $recipient = activeMemberOf($company);

    $this->actingAs($user)
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $company), [
            'enabled' => true,
            'to_user_ids' => [$recipient->id],
            'cc_user_ids' => [],
        ])
        ->assertRedirect();

    $setting = CompanyDocumentExpiryNotificationSetting::query()
        ->where('company_id', $company->id)
        ->with(['toRecipients', 'ccRecipients'])
        ->firstOrFail();

    expect($setting->enabled)->toBeTrue()
        ->and($setting->toRecipients)->toHaveCount(1)
        ->and((int) $setting->toRecipients->first()->user_id)->toBe($recipient->id)
        ->and($setting->ccRecipients)->toHaveCount(0);
});

test('authorized user can configure CC recipients', function () {
    ['company' => $company, 'user' => $user] = notificationSettingContext([
        'company_documents.view',
        'company_documents.manage_notifications',
    ]);
    $toRecipient = activeMemberOf($company);
    $ccRecipient = activeMemberOf($company);

    $this->actingAs($user)
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $company), [
            'enabled' => true,
            'to_user_ids' => [$toRecipient->id],
            'cc_user_ids' => [$ccRecipient->id],
        ])
        ->assertRedirect();

    $setting = CompanyDocumentExpiryNotificationSetting::query()
        ->where('company_id', $company->id)
        ->with(['toRecipients', 'ccRecipients'])
        ->firstOrFail();

    expect($setting->toRecipients)->toHaveCount(1)
        ->and($setting->ccRecipients)->toHaveCount(1)
        ->and((int) $setting->ccRecipients->first()->user_id)->toBe($ccRecipient->id);
});

test('enabling without TO recipients returns validation error', function () {
    ['company' => $company, 'user' => $user] = notificationSettingContext([
        'company_documents.view',
        'company_documents.manage_notifications',
    ]);

    $this->actingAs($user)
        ->from(route('organization.companies.documents.index', $company))
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $company), [
            'enabled' => true,
            'to_user_ids' => [],
            'cc_user_ids' => [],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('to_user_ids');

    $setting = CompanyDocumentExpiryNotificationSetting::query()->where('company_id', $company->id)->first();

    expect($setting)->toBeNull();
});

test('settings are persisted per company independently', function () {
    ['company' => $companyA, 'user' => $userA] = notificationSettingContext([
        'company_documents.view',
        'company_documents.manage_notifications',
    ]);
    $otherFixtures = makeDocumentFixtures();
    $userB = User::factory()->create(['company_id' => $otherFixtures['company']->id]);
    grantCompanyPermissions($userB, $otherFixtures['company'], ['company_documents.view', 'company_documents.manage_notifications']);

    $recipientA = activeMemberOf($companyA);
    $recipientB = activeMemberOf($otherFixtures['company']);

    $this->actingAs($userA)
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $companyA), [
            'enabled' => true,
            'to_user_ids' => [$recipientA->id],
            'cc_user_ids' => [],
        ])
        ->assertRedirect();

    $this->actingAs($userB)
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $otherFixtures['company']), [
            'enabled' => false,
            'to_user_ids' => [$recipientB->id],
            'cc_user_ids' => [],
        ])
        ->assertRedirect();

    expect(CompanyDocumentExpiryNotificationSetting::query()->count())->toBe(2);

    $settingA = CompanyDocumentExpiryNotificationSetting::query()
        ->where('company_id', $companyA->id)
        ->firstOrFail();

    $settingB = CompanyDocumentExpiryNotificationSetting::query()
        ->where('company_id', $otherFixtures['company']->id)
        ->firstOrFail();

    expect($settingA->enabled)->toBeTrue()
        ->and($settingB->enabled)->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// Tenant isolation tests
// ─────────────────────────────────────────────────────────────────────────────

test('cross-company TO user IDs are rejected', function () {
    ['company' => $companyA, 'user' => $userA] = notificationSettingContext([
        'company_documents.view',
        'company_documents.manage_notifications',
    ]);

    $otherFixtures = makeDocumentFixtures();
    $crossCompanyUser = User::factory()->create(['company_id' => $otherFixtures['company']->id]);
    DB::table('company_user')->insert([
        'company_id' => $otherFixtures['company']->id,
        'user_id' => $crossCompanyUser->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $validMember = activeMemberOf($companyA);

    $this->actingAs($userA)
        ->from(route('organization.companies.documents.index', $companyA))
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $companyA), [
            'enabled' => true,
            'to_user_ids' => [$validMember->id, $crossCompanyUser->id],
            'cc_user_ids' => [],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors([
            'to_user_ids' => 'One or more selected recipients are not active members of this company.',
        ]);

    expect(CompanyDocumentExpiryNotificationSetting::query()->where('company_id', $companyA->id)->exists())->toBeFalse();
});

test('company A cannot access company B settings', function () {
    ['company' => $companyA, 'user' => $userA] = notificationSettingContext([
        'company_documents.view',
        'company_documents.manage_notifications',
    ]);
    $otherFixtures = makeDocumentFixtures();

    // User A tries to PUT company B's setting route.
    $this->actingAs($userA)
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $otherFixtures['company']), [
            'enabled' => false,
            'to_user_ids' => [],
            'cc_user_ids' => [],
        ])
        ->assertNotFound();
});

// ─────────────────────────────────────────────────────────────────────────────
// Audit tests
// ─────────────────────────────────────────────────────────────────────────────

test('notification setting changes are logged to activity', function () {
    ['company' => $company, 'user' => $user] = notificationSettingContext([
        'company_documents.view',
        'company_documents.manage_notifications',
    ]);
    $recipient = activeMemberOf($company);

    $this->actingAs($user)
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $company), [
            'enabled' => true,
            'to_user_ids' => [$recipient->id],
            'cc_user_ids' => [],
        ])
        ->assertRedirect();

    // Activity log should capture the "enabled" change.
    $activity = Activity::query()
        ->where('subject_type', CompanyDocumentExpiryNotificationSetting::class)
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->company_id)->toBe($company->id)
        ->and((int) $activity->causer_id)->toBe($user->id);
});

test('cross-company CC user IDs are rejected', function () {
    ['company' => $companyA, 'user' => $userA] = notificationSettingContext([
        'company_documents.view',
        'company_documents.manage_notifications',
    ]);

    $otherFixtures = makeDocumentFixtures();
    $crossCompanyUser = activeMemberOf($otherFixtures['company']);
    $validTo = activeMemberOf($companyA);

    $this->actingAs($userA)
        ->from(route('organization.companies.documents.index', $companyA))
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $companyA), [
            'enabled' => true,
            'to_user_ids' => [$validTo->id],
            'cc_user_ids' => [$crossCompanyUser->id],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors([
            'cc_user_ids' => 'One or more selected recipients are not active members of this company.',
        ]);

    expect(CompanyDocumentExpiryNotificationSetting::query()->where('company_id', $companyA->id)->exists())->toBeFalse();
});

test('inactive company membership is rejected as a recipient', function () {
    ['company' => $company, 'user' => $user] = notificationSettingContext([
        'company_documents.view',
        'company_documents.manage_notifications',
    ]);
    $inactiveMember = activeMemberOf($company);
    DB::table('company_user')
        ->where('company_id', $company->id)
        ->where('user_id', $inactiveMember->id)
        ->update(['status' => 'inactive']);

    $this->actingAs($user)
        ->from(route('organization.companies.documents.index', $company))
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $company), [
            'enabled' => true,
            'to_user_ids' => [$inactiveMember->id],
            'cc_user_ids' => [],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('to_user_ids');

    expect(CompanyDocumentExpiryNotificationSetting::query()->where('company_id', $company->id)->exists())->toBeFalse();
});

test('nonexistent recipient user IDs are rejected', function () {
    ['company' => $company, 'user' => $user] = notificationSettingContext([
        'company_documents.view',
        'company_documents.manage_notifications',
    ]);
    $validMember = activeMemberOf($company);

    $this->actingAs($user)
        ->from(route('organization.companies.documents.index', $company))
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $company), [
            'enabled' => true,
            'to_user_ids' => [$validMember->id, 9_999_999],
            'cc_user_ids' => [],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('to_user_ids');

    expect(CompanyDocumentExpiryNotificationSetting::query()->where('company_id', $company->id)->exists())->toBeFalse();
});

test('failed recipient validation does not mutate existing configuration', function () {
    ['company' => $company, 'user' => $user] = notificationSettingContext([
        'company_documents.view',
        'company_documents.manage_notifications',
    ]);
    $original = activeMemberOf($company);
    $outsider = activeMemberOf(makeDocumentFixtures()['company']);

    $this->actingAs($user)
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $company), [
            'enabled' => true,
            'to_user_ids' => [$original->id],
            'cc_user_ids' => [],
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->from(route('organization.companies.documents.index', $company))
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $company), [
            'enabled' => false,
            'to_user_ids' => [$outsider->id],
            'cc_user_ids' => [],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('to_user_ids');

    $setting = CompanyDocumentExpiryNotificationSetting::query()
        ->where('company_id', $company->id)
        ->with(['toRecipients', 'ccRecipients'])
        ->firstOrFail();

    expect($setting->enabled)->toBeTrue()
        ->and($setting->toRecipients)->toHaveCount(1)
        ->and((int) $setting->toRecipients->first()->user_id)->toBe($original->id)
        ->and($setting->ccRecipients)->toHaveCount(0);
});

test('changing only recipients creates a single recipient audit event', function () {
    ['company' => $company, 'user' => $user] = notificationSettingContext([
        'company_documents.view',
        'company_documents.manage_notifications',
    ]);
    $first = activeMemberOf($company);
    $second = activeMemberOf($company);
    $cc = activeMemberOf($company);

    $this->actingAs($user)
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $company), [
            'enabled' => true,
            'to_user_ids' => [$first->id],
            'cc_user_ids' => [$cc->id],
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $company), [
            'enabled' => true,
            'to_user_ids' => [$second->id],
            'cc_user_ids' => [$cc->id],
        ])
        ->assertRedirect();

    $activities = Activity::query()
        ->where('event', 'company_document_expiry_notification_recipients_updated')
        ->orderBy('id')
        ->get();

    expect($activities)->toHaveCount(2);

    $latest = $activities->last();

    expect($latest->company_id)->toBe($company->id)
        ->and((int) $latest->causer_id)->toBe($user->id)
        ->and($latest->properties->get('company_id'))->toBe($company->id)
        ->and($latest->properties->get('before')['to'][0]['id'])->toBe($first->id)
        ->and($latest->properties->get('before')['to'][0]['name'])->toBe($first->name)
        ->and($latest->properties->get('before')['to'][0]['email'])->toBe($first->email)
        ->and($latest->properties->get('after')['to'][0]['id'])->toBe($second->id)
        ->and($latest->properties->get('after')['to'][0]['name'])->toBe($second->name)
        ->and($latest->properties->get('after')['cc'][0]['id'])->toBe($cc->id);
});

test('cc recipient changes are recorded in audit properties', function () {
    ['company' => $company, 'user' => $user] = notificationSettingContext([
        'company_documents.view',
        'company_documents.manage_notifications',
    ]);
    $to = activeMemberOf($company);
    $firstCc = activeMemberOf($company);
    $secondCc = activeMemberOf($company);

    $this->actingAs($user)
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $company), [
            'enabled' => true,
            'to_user_ids' => [$to->id],
            'cc_user_ids' => [$firstCc->id],
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $company), [
            'enabled' => true,
            'to_user_ids' => [$to->id],
            'cc_user_ids' => [$secondCc->id],
        ])
        ->assertRedirect();

    $latest = Activity::query()
        ->where('event', 'company_document_expiry_notification_recipients_updated')
        ->latest('id')
        ->first();

    expect($latest)->not->toBeNull()
        ->and($latest->properties->get('before')['cc'][0]['id'])->toBe($firstCc->id)
        ->and($latest->properties->get('after')['cc'][0]['id'])->toBe($secondCc->id)
        ->and($latest->properties->get('after')['to'][0]['id'])->toBe($to->id);
});

test('company documents index omits members without usable email from the picker', function () {
    ['company' => $company, 'user' => $user] = notificationSettingContext([
        'company_documents.view',
        'company_documents.manage_notifications',
    ]);
    $withEmail = activeMemberOf($company);
    $withoutEmail = User::factory()->create(['email' => 'not-an-email']);
    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $withoutEmail->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('organization.companies.documents.index', $company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('company_users', function ($users) use ($withEmail, $withoutEmail, $user): bool {
                $ids = collect($users)->pluck('id')->all();

                return in_array($withEmail->id, $ids, true)
                    && in_array($user->id, $ids, true)
                    && ! in_array($withoutEmail->id, $ids, true);
            })
        );
});
