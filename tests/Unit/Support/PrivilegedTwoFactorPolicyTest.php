<?php

use App\Models\User;
use App\Support\Auth\PrivilegedTwoFactorPolicy;
use App\Support\Platform\PlatformAuthorization;

test('ordinary capabilities do not require privileged two-factor', function (string $permission) {
    expect(PrivilegedTwoFactorPolicy::requiresPermission($permission))->toBeFalse();
})->with([
    'employees.view',
    'attendance.leave-requests.create',
    'attendance.leave-requests.approve',
    'payroll.periods.view',
    'roles.view',
    'users.view',
    'settings.application.view',
    'settings.integrations.whatsapp.view',
    'companies.update',
    'crew_operations.corrections.approve',
    'payroll.periods.cancel',
]);

test('high-trust capabilities require privileged two-factor', function (string $permission) {
    expect(PrivilegedTwoFactorPolicy::requiresPermission($permission))->toBeTrue();
})->with([
    'roles.create',
    'roles.update',
    'roles.delete',
    'users.create',
    'users.update',
    'users.delete',
    'settings.application.update',
    'settings.integrations.whatsapp.update',
    'settings.integrations.hikvision.update',
    'hikvision.webhook.manage',
    'payroll.periods.approve',
    'payroll.periods.mark_paid',
    'payroll.wps.export',
    'crew_operations.assignments.void',
    'crew_operations.corrections.override',
    'attendance.leave-requests.delete_any',
]);

test('a user with only ordinary permissions does not hold a privileged capability', function () {
    $user = User::factory()->create();
    $company = setupCompanyWithSettingsPermissions($user, [
        'employees.view',
        'attendance.leave-requests.create',
    ]);

    expect($company)->not->toBeNull()
        ->and(PrivilegedTwoFactorPolicy::userHoldsPrivilegedCapability($user))->toBeFalse();
});

test('a user with a catalog permission holds a privileged capability', function () {
    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['roles.update']);

    expect(PrivilegedTwoFactorPolicy::userHoldsPrivilegedCapability($user))->toBeTrue();
});

test('platform access is privileged even without catalog permissions', function () {
    $user = User::factory()->create();
    grantPlatformAccess($user, 'view');

    expect(PlatformAuthorization::canView($user))->toBeTrue()
        ->and(PrivilegedTwoFactorPolicy::userHoldsPrivilegedCapability($user))->toBeTrue()
        ->and(PrivilegedTwoFactorPolicy::userHoldsPrivilegedCapability(
            grantPlatformAccess(User::factory()->create(), 'manage'),
        ))->toBeTrue();
});

test('enforcement off treats every user as satisfied', function () {
    disablePrivilegedTwoFactorEnforcement();

    $user = User::factory()->create();

    expect(PrivilegedTwoFactorPolicy::isEnforced())->toBeFalse()
        ->and(PrivilegedTwoFactorPolicy::isSatisfied($user))->toBeTrue()
        ->and(PrivilegedTwoFactorPolicy::sharedFlags($user))->toMatchArray([
            'enabled' => false,
            'required_for_privileged_actions' => false,
        ]);
});

test('enforcement requires fortify-confirmed enrollment', function () {
    enablePrivilegedTwoFactorEnforcement();

    $unenrolled = User::factory()->create();
    $unconfirmed = makeUnconfirmedTwoFactorUser();
    $enrolled = User::factory()->withTwoFactor()->create();

    expect($unenrolled->hasEnabledTwoFactorAuthentication())->toBeFalse()
        ->and(PrivilegedTwoFactorPolicy::isSatisfied($unenrolled))->toBeFalse()
        ->and($unconfirmed->two_factor_secret)->not->toBeNull()
        ->and(PrivilegedTwoFactorPolicy::userHasEnrolledTwoFactor($unconfirmed))->toBeFalse()
        ->and(PrivilegedTwoFactorPolicy::isSatisfied($unconfirmed))->toBeFalse()
        ->and(PrivilegedTwoFactorPolicy::isSatisfied($enrolled))->toBeTrue();
});

test('shared flags mark unenrolled privileged users as requiring enrollment', function () {
    enablePrivilegedTwoFactorEnforcement();

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, ['payroll.periods.approve']);

    expect(PrivilegedTwoFactorPolicy::sharedFlags($user))->toMatchArray([
        'enabled' => false,
        'required_for_privileged_actions' => true,
    ]);
});

test('shared flags do not treat enrollment as a permission grant', function () {
    enablePrivilegedTwoFactorEnforcement();

    $user = User::factory()->withTwoFactor()->create();
    setupCompanyWithSettingsPermissions($user, ['employees.view']);

    expect(PrivilegedTwoFactorPolicy::sharedFlags($user))->toMatchArray([
        'enabled' => true,
        'required_for_privileged_actions' => false,
    ]);
});
