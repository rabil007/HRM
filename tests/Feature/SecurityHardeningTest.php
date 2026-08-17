<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;

function makeSecurityHardeningCompany(): Company
{
    $country = Country::query()->create([
        'code' => 'SEC',
        'name' => 'Securityland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'SCY',
        'name' => 'Security Currency',
        'symbol' => 'S$',
        'is_active' => true,
    ]);

    return Company::query()->create([
        'name' => 'Security Co',
        'slug' => 'security-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

test('web responses include conservative security headers', function () {
    $this->get('/')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Permissions-Policy', 'geolocation=(), microphone=(), payment=()');
});

test('privileged two factor enforcement is disabled by default', function () {
    $user = User::factory()->create();
    $company = makeSecurityHardeningCompany();
    grantCompanyPermissions($user, $company, [
        'roles.update',
        'settings.security.view',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/dashboard')
        ->assertOk();
});

test('enabled privileged two factor enforcement redirects unenrolled privileged users to security settings', function () {
    config()->set('security.privileged_two_factor.enforce', true);

    $user = User::factory()->create();
    $company = makeSecurityHardeningCompany();
    grantCompanyPermissions($user, $company, [
        'roles.update',
        'settings.security.view',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/dashboard')
        ->assertRedirect(route('security.edit'));
});
