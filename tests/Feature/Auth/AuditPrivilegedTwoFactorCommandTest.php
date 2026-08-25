<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Support\Auth\PrivilegedTwoFactorEnrollmentAudit;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

test('privileged two-factor audit succeeds when no privileged users exist', function () {
    User::factory()->create([
        'email' => 'ordinary@example.com',
        'status' => 'active',
    ]);

    $this->artisan('security:audit-privileged-2fa')
        ->expectsOutput('All active privileged and platform users have confirmed Fortify 2FA. Rollout is ready.')
        ->assertSuccessful();
});

test('privileged two-factor audit succeeds when privileged users have confirmed enrollment', function () {
    $user = User::factory()->withTwoFactor()->create([
        'email' => 'enrolled.admin@example.com',
    ]);
    setupCompanyWithSettingsPermissions($user, ['roles.create']);
    grantPlatformAccess($user, 'view');

    $this->artisan('security:audit-privileged-2fa')
        ->expectsOutput('All active privileged and platform users have confirmed Fortify 2FA. Rollout is ready.')
        ->assertSuccessful();
});

test('privileged two-factor audit fails for unenrolled catalog and platform users without exposing secrets', function () {
    $secret = 'distinct-2fa-seed-do-not-print';
    $recoveryCode = 'recovery-code-do-not-print';

    $catalogUser = User::factory()->create([
        'email' => 'payroll.approver@example.com',
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode([$recoveryCode])),
        'two_factor_confirmed_at' => null,
    ]);
    $company = setupCompanyWithSettingsPermissions($catalogUser, ['payroll.periods.approve']);

    $platformUser = User::factory()->create([
        'email' => 'platform.viewer@example.com',
    ]);
    grantPlatformAccess($platformUser, 'view');

    $ordinary = User::factory()->create([
        'email' => 'ordinary.employee@example.com',
    ]);

    $rows = app(PrivilegedTwoFactorEnrollmentAudit::class)->unenrolledActiveUsers();

    expect($rows)->toHaveCount(2)
        ->and(collect($rows)->pluck('id'))->not->toContain($ordinary->id)
        ->and(collect($rows)->pluck('email')->sort()->values()->all())->toBe([
            'payroll.approver@example.com',
            'platform.viewer@example.com',
        ])
        ->and($rows[0])->not->toHaveKey('two_factor_secret')
        ->and($rows[0])->not->toHaveKey('two_factor_recovery_codes')
        ->and($rows[0])->not->toHaveKey('password')
        ->and($rows[0]['enrollment'])->toBe('unconfirmed')
        ->and(collect($rows)->firstWhere('email', 'platform.viewer@example.com')['capabilities'])
        ->toContain('platform:view');

    $this->artisan('security:audit-privileged-2fa')
        ->assertFailed()
        ->expectsOutputToContain('payroll.approver@example.com')
        ->doesntExpectOutputToContain($secret)
        ->doesntExpectOutputToContain($recoveryCode)
        ->doesntExpectOutputToContain('ordinary.employee@example.com');

    expect($catalogUser->fresh()->two_factor_confirmed_at)->toBeNull()
        ->and($platformUser->fresh()->platform_access?->value)->toBe('view');
});

test('privileged two-factor audit finds team-scoped permissions in a non-current company', function () {
    $country = Country::query()->create([
        'code' => 'P2C',
        'name' => 'Privileged Two Company Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'P2C',
        'name' => 'Privileged Two Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);
    $companyA = Company::query()->create([
        'name' => 'Audit Company A',
        'slug' => 'audit-company-a',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $companyB = Company::query()->create([
        'name' => 'Audit Company B',
        'slug' => 'audit-company-b',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'email' => 'cross.company.admin@example.com',
        'company_id' => $companyA->id,
    ]);

    grantCompanyPermissions($user, $companyA, ['employees.view']);
    grantCompanyPermissions($user, $companyB, ['roles.create']);

    app(PermissionRegistrar::class)->setPermissionsTeamId($companyA->id);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');

    $rows = app(PrivilegedTwoFactorEnrollmentAudit::class)->unenrolledActiveUsers();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['email'])->toBe('cross.company.admin@example.com')
        ->and($rows[0]['capabilities'])->toContain('roles.create@'.$companyB->id)
        ->and($rows[0]['capabilities'])->not->toContain('employees.view@'.$companyA->id);

    $this->artisan('security:audit-privileged-2fa')
        ->assertFailed()
        ->expectsOutputToContain('cross.company.admin@example.com');
});

test('privileged two-factor audit includes direct permission assignments', function () {
    $user = User::factory()->create([
        'email' => 'direct.permission@example.com',
    ]);
    $company = setupCompanyWithSettingsPermissions($user, ['employees.view']);

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $permission = Permission::query()->firstOrCreate([
        'name' => 'users.delete',
        'guard_name' => 'web',
    ]);
    $user->givePermissionTo($permission);

    $rows = app(PrivilegedTwoFactorEnrollmentAudit::class)->unenrolledActiveUsers();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['email'])->toBe('direct.permission@example.com')
        ->and($rows[0]['capabilities'])->toContain('users.delete@'.$company->id);

    $this->artisan('security:audit-privileged-2fa')
        ->assertFailed()
        ->expectsOutputToContain('direct.permission@example.com');
});

test('privileged two-factor audit omits inactive users from the blocking list', function () {
    $inactive = User::factory()->create([
        'email' => 'inactive.admin@example.com',
        'status' => 'inactive',
    ]);
    grantPlatformAccess($inactive, 'manage');
    setupCompanyWithSettingsPermissions($inactive, ['roles.delete']);

    $this->artisan('security:audit-privileged-2fa')
        ->expectsOutput('All active privileged and platform users have confirmed Fortify 2FA. Rollout is ready.')
        ->expectsOutputToContain('1 inactive privileged or platform user(s) omitted')
        ->doesntExpectOutputToContain('inactive.admin@example.com')
        ->assertSuccessful();
});

test('privileged two-factor audit does not decrypt or return stored 2FA material', function () {
    $user = User::factory()->create([
        'email' => 'cipher.admin@example.com',
        'two_factor_secret' => encrypt('cipher-seed-must-stay-hidden'),
        'two_factor_recovery_codes' => encrypt(json_encode(['cipher-recovery-must-stay-hidden'])),
        'two_factor_confirmed_at' => null,
    ]);
    grantPlatformAccess($user, 'manage');

    $cipherSecret = $user->getRawOriginal('two_factor_secret');
    $cipherRecovery = $user->getRawOriginal('two_factor_recovery_codes');

    expect($cipherSecret)->not->toBeNull()
        ->and($cipherRecovery)->not->toBeNull();

    $rows = app(PrivilegedTwoFactorEnrollmentAudit::class)->unenrolledActiveUsers();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['email'])->toBe('cipher.admin@example.com')
        ->and($rows[0]['enrollment'])->toBe('unconfirmed')
        ->and($rows[0])->not->toHaveKey('two_factor_secret')
        ->and($rows[0])->not->toHaveKey('two_factor_recovery_codes');

    $this->artisan('security:audit-privileged-2fa')
        ->assertFailed()
        ->expectsOutputToContain('cipher.admin@example.com')
        ->doesntExpectOutputToContain('cipher-seed-must-stay-hidden')
        ->doesntExpectOutputToContain('cipher-recovery-must-stay-hidden')
        ->doesntExpectOutputToContain((string) $cipherSecret)
        ->doesntExpectOutputToContain((string) $cipherRecovery);

    expect($user->fresh()->getRawOriginal('two_factor_secret'))->toBe($cipherSecret)
        ->and($user->fresh()->getRawOriginal('two_factor_recovery_codes'))->toBe($cipherRecovery);
});
