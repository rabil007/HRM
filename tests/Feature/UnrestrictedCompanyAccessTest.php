<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Support\Auth\PrivilegedTwoFactorPolicy;
use App\Support\Auth\UnrestrictedCompanyAccess;
use Database\Seeders\PermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

function unrestrictedAccessCompany(string $slug = 'unrestricted-co'): Company
{
    $country = Country::query()->firstOrCreate(
        ['code' => 'UAE'],
        ['name' => 'United Arab Emirates', 'dial_code' => '+971', 'is_active' => true],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        ['name' => 'UAE Dirham', 'symbol' => 'AED', 'is_active' => true],
    );

    return Company::query()->create([
        'name' => 'Unrestricted Co',
        'slug' => $slug,
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

function attachUserToCompanyWithoutPermissions(User $user, Company $company): void
{
    $user->forceFill(['company_id' => $company->id])->save();
    $user->companies()->syncWithoutDetaching([
        $company->id => ['status' => 'active'],
    ]);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
}

test('demo admin has full catalog access without assigned permissions', function () {
    $this->seed(PermissionsSeeder::class);
    $company = unrestrictedAccessCompany();

    $admin = User::factory()->create([
        'email' => UnrestrictedCompanyAccess::EMAIL,
        'company_id' => $company->id,
    ]);
    attachUserToCompanyWithoutPermissions($admin, $company);

    expect($admin->getRoleNames()->all())->toBe([])
        ->and($admin->getAllPermissions()->all())->toBe([])
        ->and($admin->can('companies.view'))->toBeTrue()
        ->and($admin->can('employees.delete'))->toBeTrue()
        ->and($admin->can('not-a-catalog-permission'))->toBeFalse()
        ->and(PrivilegedTwoFactorPolicy::userHoldsPrivilegedCapability($admin))->toBeTrue();

    $this->actingAs($admin)
        ->withSession(['current_company_id' => $company->id])
        ->get('/organization/companies')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.permissions', function ($permissions) {
                $names = collect($permissions);

                return $names->contains('companies.view')
                    && $names->contains('employees.delete')
                    && $names->contains('roles.update');
            })
        );
});

test('other users still require assigned permissions', function () {
    $this->seed(PermissionsSeeder::class);
    $company = unrestrictedAccessCompany('ordinary-co');

    $user = User::factory()->create([
        'email' => 'member@example.com',
        'company_id' => $company->id,
    ]);
    attachUserToCompanyWithoutPermissions($user, $company);

    expect($user->can('companies.view'))->toBeFalse();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/organization/companies')
        ->assertForbidden();
});
