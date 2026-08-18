<?php

use App\Enums\PayrollPeriodStatus;
use App\Models\User;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->seed(PermissionsSeeder::class);
});

test('user holding privileged permission without settings.security.view can self-serve enroll 2fa and complete action', function () {
    enablePrivilegedTwoFactorEnforcement();
    Storage::fake('local');

    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.overview.view',
        'payroll.periods.view',
        'payroll.periods.approve',
    ]);

    [$period] = makeProcessingPayrollPeriodWithRecord($company);

    // 1. Privileged action is blocked and redirects to security.edit
    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.approve', $period))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('error');

    expect($period->fresh()->status)->toBe(PayrollPeriodStatus::Processing);

    // 2. User can reach personal security / 2FA enrollment page without settings.security.view
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk();

    // 3. User can enable Fortify 2FA
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.enable'))
        ->assertRedirect();

    $user->refresh();
    expect($user->two_factor_secret)->not->toBeNull();

    // Confirm 2FA using valid OTP code
    $google2fa = new Google2FA;
    $validOtp = $google2fa->getCurrentOtp(decrypt($user->two_factor_secret));

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.confirm'), [
            'code' => $validOtp,
        ])
        ->assertRedirect();

    $user->refresh();
    expect($user->two_factor_confirmed_at)->not->toBeNull();

    // 4. After enrollment, privileged action succeeds
    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.approve', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]));

    expect($period->fresh()->status)->toBe(PayrollPeriodStatus::Approved);

    // 5. User does not gain unrelated security administration permissions
    expect($user->can('settings.security.view'))->toBeFalse()
        ->and($user->can('settings.security.update'))->toBeFalse()
        ->and($user->can('settings.application.update'))->toBeFalse()
        ->and($user->platform_access)->toBeNull();
});

test('unauthenticated guest cannot access security enrollment or password update', function () {
    $this->get(route('security.edit'))
        ->assertRedirect(route('login'));

    $this->put(route('user-password.update'), [
        'current_password' => 'password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertRedirect(route('login'));

    $this->post(route('two-factor.enable'))
        ->assertRedirect(route('login'));
});

test('user can only modify their own 2fa state and cannot affect another user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    $this->actingAs($userA)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->post(route('two-factor.enable'))
        ->assertRedirect();

    expect($userA->refresh()->two_factor_secret)->not->toBeNull()
        ->and($userB->refresh()->two_factor_secret)->toBeNull();
});

test('disabling 2fa immediately re-blocks privileged actions', function () {
    enablePrivilegedTwoFactorEnforcement();
    Storage::fake('local');

    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, [
        'payroll.overview.view',
        'payroll.periods.view',
        'payroll.periods.approve',
    ]);

    $google2fa = new Google2FA;
    $secret = $google2fa->generateSecretKey();
    $user->forceFill([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    [$period1] = makeProcessingPayrollPeriodWithRecord($company);

    // Privileged action works when 2FA is active
    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.approve', $period1))
        ->assertRedirect();

    expect($period1->fresh()->status)->toBe(PayrollPeriodStatus::Approved);

    // Disable 2FA
    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('two-factor.disable'))
        ->assertRedirect();

    expect($user->refresh()->two_factor_confirmed_at)->toBeNull();

    [$period2] = makeProcessingPayrollPeriodWithRecord($company);

    // Privileged action is now blocked again
    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.approve', $period2))
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('error');

    expect($period2->fresh()->status)->toBe(PayrollPeriodStatus::Processing);
});
