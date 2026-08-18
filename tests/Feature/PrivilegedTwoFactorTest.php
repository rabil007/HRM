<?php

use App\Enums\PayrollPeriodStatus;
use App\Exceptions\PrivilegedTwoFactorRequiredException;
use App\Models\AppSetting;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Models\WhatsAppSetting;
use App\Support\Auth\PrivilegedTwoFactorPolicy;
use App\Support\CrewMovements\Corrections\RequestCrewMovementCorrection;
use App\Support\Settings\SettingKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

test('unenrolled authorized users are blocked from privileged mutations and can still enroll', function () {
    enablePrivilegedTwoFactorEnforcement();

    $user = User::factory()->create();
    $company = setupCompanyWithSettingsPermissions($user, [
        'roles.view',
        'roles.create',
        'roles.update',
        'settings.security.view',
        'employees.view',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('dashboard'))
        ->assertOk();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.roles'))
        ->assertOk();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.roles.store'), [
            'name' => 'Privileged Role',
            'permissions' => ['employees.view'],
        ])
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('error', PrivilegedTwoFactorRequiredException::MESSAGE);

    expect(Role::query()->where('company_id', $company->id)->where('name', 'Privileged Role')->exists())->toBeFalse();

    $this->actingAs($user)
        ->withSession([
            'current_company_id' => $company->id,
            'auth.password_confirmed_at' => time(),
        ])
        ->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/security')
            ->where('auth.two_factor.enabled', false)
            ->where('auth.two_factor.required_for_privileged_actions', true)
            ->missing('auth.user.two_factor_secret')
            ->missing('auth.user.two_factor_recovery_codes')
            ->missing('auth.user.two_factor_confirmed_at'));
});

test('enrolled users can perform privileged role mutations', function () {
    enablePrivilegedTwoFactorEnforcement();

    $user = User::factory()->withTwoFactor()->create();
    $company = setupCompanyWithSettingsPermissions($user, ['roles.create', 'roles.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.roles.store'), [
            'name' => 'Enrolled Role',
            'permissions' => ['employees.view'],
        ])
        ->assertRedirect();

    expect(Role::query()->where('company_id', $company->id)->where('name', 'Enrolled Role')->exists())->toBeTrue();
});

test('two-factor enrollment does not grant missing permissions', function () {
    enablePrivilegedTwoFactorEnforcement();

    $user = User::factory()->withTwoFactor()->create();
    $company = setupCompanyWithSettingsPermissions($user, ['roles.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.roles.store'), [
            'name' => 'Should Fail',
            'permissions' => ['employees.view'],
        ])
        ->assertForbidden();

    expect(Role::query()->where('name', 'Should Fail')->exists())->toBeFalse();
});

test('privileged two-factor is skipped when enforcement is disabled', function () {
    disablePrivilegedTwoFactorEnforcement();

    $user = User::factory()->create();
    $company = setupCompanyWithSettingsPermissions($user, ['roles.create', 'roles.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.roles.store'), [
            'name' => 'Unenforced Role',
            'permissions' => ['employees.view'],
        ])
        ->assertRedirect();

    expect(Role::query()->where('company_id', $company->id)->where('name', 'Unenforced Role')->exists())->toBeTrue();
});

test('unconfirmed two-factor secrets do not satisfy privileged enforcement', function () {
    enablePrivilegedTwoFactorEnforcement();

    $user = makeUnconfirmedTwoFactorUser();
    $company = setupCompanyWithSettingsPermissions($user, ['roles.create', 'roles.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.roles.store'), [
            'name' => 'Unconfirmed Role',
            'permissions' => ['employees.view'],
        ])
        ->assertRedirect(route('security.edit'));

    expect(Role::query()->where('name', 'Unconfirmed Role')->exists())->toBeFalse()
        ->and(PrivilegedTwoFactorPolicy::userHasEnrolledTwoFactor($user->fresh()))->toBeFalse();
});

test('disabling two-factor immediately blocks privileged actions', function () {
    enablePrivilegedTwoFactorEnforcement();

    $user = User::factory()->withTwoFactor()->create();
    $company = setupCompanyWithSettingsPermissions($user, ['roles.create', 'roles.view']);

    $user->forceFill([
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
    ])->save();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('organization.roles.store'), [
            'name' => 'After Disable',
            'permissions' => ['employees.view'],
        ])
        ->assertRedirect(route('security.edit'));

    expect(Role::query()->where('name', 'After Disable')->exists())->toBeFalse();
});

test('platform access without two-factor is blocked when enforcement is on', function () {
    enablePrivilegedTwoFactorEnforcement();

    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    setupCompanyWithSettingsPermissions($user, ['settings.security.view']);

    $this->actingAs($user)
        ->get('/log')
        ->assertRedirect(route('security.edit'))
        ->assertSessionHas('error', PrivilegedTwoFactorRequiredException::MESSAGE);

    $this->actingAs($user)
        ->delete('/log', ['scope' => 'all'])
        ->assertRedirect(route('security.edit'));
});

test('enrolled platform users can open diagnostic surfaces', function () {
    enablePrivilegedTwoFactorEnforcement();

    $user = User::factory()->withTwoFactor()->create();
    grantPlatformAccess($user, 'view');

    $this->actingAs($user)
        ->get('/log')
        ->assertOk();
});

test('unenrolled users cannot assign roles through users.update', function () {
    enablePrivilegedTwoFactorEnforcement();

    $actor = User::factory()->create();
    $company = setupCompanyWithSettingsPermissions($actor, ['users.update', 'users.view']);

    $target = User::factory()->create(['company_id' => $company->id]);
    DB::table('company_user')->updateOrInsert(
        ['company_id' => $company->id, 'user_id' => $target->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );

    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Owner',
        'guard_name' => 'web',
    ]);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $company->id])
        ->put(route('organization.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'password' => '',
            'role_id' => $role->id,
            'status' => 'active',
        ])
        ->assertRedirect(route('security.edit'));

    $this->assertDatabaseMissing('spatie_model_has_roles', [
        'company_id' => $company->id,
        'role_id' => $role->id,
        'model_id' => $target->id,
    ]);
});

test('enrolled users can assign roles through users.update', function () {
    enablePrivilegedTwoFactorEnforcement();

    $actor = User::factory()->withTwoFactor()->create();
    $company = setupCompanyWithSettingsPermissions($actor, ['users.update', 'users.view']);

    $target = User::factory()->create(['company_id' => $company->id]);
    DB::table('company_user')->updateOrInsert(
        ['company_id' => $company->id, 'user_id' => $target->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );

    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Owner',
        'guard_name' => 'web',
    ]);

    $this->actingAs($actor)
        ->withSession(['current_company_id' => $company->id])
        ->put(route('organization.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'password' => '',
            'role_id' => $role->id,
            'status' => 'active',
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('spatie_model_has_roles', [
        'company_id' => $company->id,
        'role_id' => $role->id,
        'model_id' => $target->id,
    ]);
});

test('payroll approval is not mutated without two-factor', function () {
    enablePrivilegedTwoFactorEnforcement();

    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    grantCompanyPermissions($user, $company, ['payroll.periods.approve']);

    [$period] = makeProcessingPayrollPeriodWithRecord($company);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.approve', $period))
        ->assertRedirect(route('security.edit'));

    expect($period->fresh()->status)->toBe(PayrollPeriodStatus::Processing)
        ->and($period->fresh()->approved_by)->toBeNull();
});

test('enrolled users can approve payroll', function () {
    enablePrivilegedTwoFactorEnforcement();

    ['user' => $user, 'company' => $company] = makePayrollFixtures();
    $user->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    grantCompanyPermissions($user, $company, ['payroll.periods.approve']);

    Storage::fake('local');

    [$period] = makeProcessingPayrollPeriodWithRecord($company);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->post(route('payroll.approve', $period))
        ->assertRedirect(route('payroll.show', ['payrollPeriod' => $period]));

    expect($period->fresh()->status)->toBe(PayrollPeriodStatus::Approved);
});

test('smtp credential mutation is blocked without two-factor and the stored secret is unchanged', function () {
    enablePrivilegedTwoFactorEnforcement();

    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::MailPassword],
        ['value' => Crypt::encryptString('keep-me'), 'type' => 'encrypted'],
    );
    Cache::forget('app.settings.all');

    $user = User::factory()->create();
    grantPlatformAccess($user, 'manage');
    setupCompanyWithSettingsPermissions($user, [
        'settings.security.view',
    ]);

    $this->actingAs($user)
        ->post(route('application.smtp.update'), [
            'host' => 'smtp.example.com',
            'port' => 587,
            'username' => 'hr@example.com',
            'password' => 'attacker-pass',
            'encryption' => 'tls',
            'from_address' => 'hr@example.com',
            'from_name' => 'OMS',
        ])
        ->assertRedirect(route('security.edit'));

    Cache::forget('app.settings.all');

    expect(Crypt::decryptString((string) setting(SettingKey::MailPassword)))->toBe('keep-me');
});

test('direct json credential updates fail without two-factor', function () {
    enablePrivilegedTwoFactorEnforcement();

    WhatsAppSetting::current()->storeFromValidated(privilegedTwoFactorWhatsAppPayload([
        'access_token' => 'keep-access-token',
        'app_secret' => 'keep-app-secret',
        'webhook_verify_token' => 'keep-verify',
    ]));

    $user = User::factory()->create();
    setupCompanyWithSettingsPermissions($user, [
        'settings.integrations.whatsapp.update',
        'settings.integrations.whatsapp.view',
    ]);

    $this->actingAs($user)
        ->putJson(route('application.whatsapp.update'), privilegedTwoFactorWhatsAppPayload([
            'access_token' => 'stolen-token',
            'app_secret' => 'stolen-secret',
        ]))
        ->assertForbidden()
        ->assertJson(['message' => PrivilegedTwoFactorRequiredException::MESSAGE]);

    $settings = WhatsAppSetting::current()->fresh();

    expect($settings->access_token)->toBe('keep-access-token')
        ->and($settings->app_secret)->toBe('keep-app-secret');
});

test('company switching still uses tenant permissions rather than two-factor enrollment', function () {
    enablePrivilegedTwoFactorEnforcement();

    $country = Country::query()->create([
        'code' => 'P2A',
        'name' => 'Two Factor Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'P2A',
        'name' => 'Two Factor Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);
    $companyA = Company::query()->create([
        'name' => 'Company A 2FA',
        'slug' => 'company-a-2fa',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
    $companyB = Company::query()->create([
        'name' => 'Company B 2FA',
        'slug' => 'company-b-2fa',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $user = User::factory()->withTwoFactor()->create();
    grantCompanyPermissions($user, $companyA, ['roles.create', 'roles.view']);
    DB::table('company_user')->updateOrInsert(
        ['company_id' => $companyB->id, 'user_id' => $user->id],
        ['status' => 'active', 'created_at' => now(), 'updated_at' => now()],
    );

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyB->id])
        ->post(route('organization.roles.store'), [
            'name' => 'Tenant B Role',
            'permissions' => ['employees.view'],
        ])
        ->assertForbidden();

    expect(Role::query()->where('company_id', $companyB->id)->where('name', 'Tenant B Role')->exists())->toBeFalse();
});

test('crew override self-approval requires two-factor when enforcement is on', function () {
    enablePrivilegedTwoFactorEnforcement();

    $fixtures = makeCrewAssignmentFixtures();
    $user = $fixtures['user'];
    $user->update(['current_company_id' => $fixtures['company']->id]);
    grantCompanyPermissions($user, $fixtures['company'], [
        'crew_operations.corrections.view',
        'crew_operations.corrections.request',
        'crew_operations.corrections.approve',
        'crew_operations.corrections.override',
        'settings.security.view',
    ]);

    $vessel = makeCrewMovementVessel('Override 2FA Vessel');
    $assignment = makeActiveOnVesselAssignment(
        $fixtures['company'],
        $fixtures['employee'],
        $fixtures['rank'],
        $vessel,
    );
    $phase = $assignment->currentPhase;
    $proposedStart = $phase->actual_start_at->copy()->addDay()->timezone($fixtures['company']->timezone)->format('Y-m-d H:i');

    $correction = app(RequestCrewMovementCorrection::class)->handle(
        $assignment,
        $phase,
        $user,
        ['actual_start_at' => $proposedStart],
        'Override request',
    );

    $this->actingAs($user)
        ->post(route('organization.crew-movement-corrections.approve', $correction))
        ->assertRedirect(route('security.edit'));

    expect($correction->fresh()->status->value)->toBe('pending');
});
