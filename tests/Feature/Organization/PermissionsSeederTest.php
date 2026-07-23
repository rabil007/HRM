<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('permissions seeder creates expected permissions and is idempotent', function () {
    expect(Permission::query()->count())->toBeGreaterThanOrEqual(0);

    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);
    $countAfterFirst = Permission::query()->where('guard_name', 'web')->count();

    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);
    $countAfterSecond = Permission::query()->where('guard_name', 'web')->count();

    expect($countAfterSecond)->toBe($countAfterFirst);

    expect(Permission::query()->where('name', 'companies.view')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'companies.update')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'company_documents.view')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'settings.application.view')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'settings.application.update')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'users.export')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'reports.crew_movement_history.view')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'reports.crew_movement_history.export')->exists())->toBeTrue();

    expect(Permission::query()->where('name', 'company.settings.view')->exists())->toBeFalse();
    expect(Permission::query()->where('name', 'company.settings.update')->exists())->toBeFalse();
    expect(Permission::query()->where('name', 'company.document-settings.view')->exists())->toBeFalse();
    expect(Permission::query()->where('name', 'company.document-settings.update')->exists())->toBeFalse();
    expect(Permission::query()->where('name', 'platform.settings.view')->exists())->toBeFalse();
    expect(Permission::query()->where('name', 'platform.settings.update')->exists())->toBeFalse();
});

test('roles page does not expose a platform permission group after seeding', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);

    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'ROL',
        'name' => 'Role Land',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->firstOrCreate(
        ['code' => 'AED'],
        [
            'name' => 'Dirham',
            'symbol' => 'د.إ',
            'is_active' => true,
        ],
    );
    $company = Company::query()->create([
        'name' => 'Role Co',
        'slug' => 'role-co-permissions',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Viewer',
        'guard_name' => 'web',
    ]);
    $role->syncPermissions(['settings.application.view', 'settings.application.update']);

    grantCompanyPermissions($user, $company, ['roles.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get("/organization/roles/{$role->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/role')
            ->has('permissions')
            ->where('permissions', function ($permissions) {
                $names = collect($permissions)->pluck('name');

                return $names->contains('settings.application.view')
                    && $names->contains('settings.application.update')
                    && ! $names->contains('platform.settings.view')
                    && ! $names->contains('platform.settings.update');
            }),
        );
});
