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
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $company), [
            'enabled' => true,
            'to_user_ids' => [],
            'cc_user_ids' => [],
        ])
        ->assertRedirect();

    // No setting should have been created with enabled=true and no recipients.
    $setting = CompanyDocumentExpiryNotificationSetting::query()->where('company_id', $company->id)->first();

    expect($setting?->enabled)->not->toBeTrue();
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

test('cross-company user IDs are silently rejected', function () {
    ['company' => $companyA, 'user' => $userA] = notificationSettingContext([
        'company_documents.view',
        'company_documents.manage_notifications',
    ]);

    // Create a user that only belongs to a different company.
    $otherFixtures = makeDocumentFixtures();
    $crossCompanyUser = User::factory()->create(['company_id' => $otherFixtures['company']->id]);
    DB::table('company_user')->insert([
        'company_id' => $otherFixtures['company']->id,
        'user_id' => $crossCompanyUser->id,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Try to set cross-company user as TO recipient for Company A.
    // Must also include a valid TO member to avoid the "no TO recipients" error.
    $validMember = activeMemberOf($companyA);

    $this->actingAs($userA)
        ->put(route('organization.companies.documents.expiry-notification-settings.update', $companyA), [
            'enabled' => true,
            'to_user_ids' => [$validMember->id, $crossCompanyUser->id],
            'cc_user_ids' => [],
        ])
        ->assertRedirect();

    $setting = CompanyDocumentExpiryNotificationSetting::query()
        ->where('company_id', $companyA->id)
        ->with('toRecipients')
        ->firstOrFail();

    // Cross-company user ID must have been filtered out.
    expect($setting->toRecipients)->toHaveCount(1)
        ->and((int) $setting->toRecipients->first()->user_id)->toBe($validMember->id);
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
        ->and($activity->company_id)->toBe($company->id);
});
