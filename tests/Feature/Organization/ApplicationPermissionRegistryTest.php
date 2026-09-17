<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Permission;
use App\Models\User;
use App\Support\Authorization\ApplicationPermissionRegistry;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

test('every application permission definition includes meaningful metadata', function () {
    $placeholders = ApplicationPermissionRegistry::placeholderDescriptionPatterns();

    foreach (ApplicationPermissionRegistry::definitions() as $permission) {
        expect($permission)->toHaveKeys(['name', 'label', 'description', 'group'])
            ->and($permission['name'])->toBeString()->not->toBeEmpty()
            ->and($permission['label'])->toBeString()->not->toBeEmpty()
            ->and($permission['description'])->toBeString()->not->toBeEmpty()
            ->and($permission['group'])->toBeString()->not->toBeEmpty()
            ->and(strlen($permission['description']))->toBeGreaterThan(20);

        foreach ($placeholders as $placeholder) {
            expect(str_contains($permission['description'], $placeholder))->toBeFalse();
        }
    }
});

test('permissions seeder syncs labels and descriptions without changing permission ids', function () {
    Artisan::call('db:seed', ['--class' => PermissionsSeeder::class]);

    $firstPass = Permission::query()
        ->where('guard_name', 'web')
        ->orderBy('name')
        ->get(['id', 'name', 'label', 'description'])
        ->keyBy('name');

    $roleHasPermissions = config('permission.table_names.role_has_permissions');
    $assignmentsBefore = DB::table($roleHasPermissions)->count();

    Artisan::call('db:seed', ['--class' => PermissionsSeeder::class]);

    $secondPass = Permission::query()
        ->where('guard_name', 'web')
        ->orderBy('name')
        ->get(['id', 'name', 'label', 'description'])
        ->keyBy('name');

    expect($secondPass->count())->toBe($firstPass->count())
        ->and(DB::table($roleHasPermissions)->count())->toBe($assignmentsBefore);

    foreach (ApplicationPermissionRegistry::definitions() as $definition) {
        $permission = $secondPass->get($definition['name']);

        expect($permission)->not->toBeNull()
            ->and($permission->id)->toBe($firstPass->get($definition['name'])->id)
            ->and($permission->label)->toBe($definition['label'])
            ->and($permission->description)->toBe($definition['description']);
    }
});

test('role details page exposes permission labels and descriptions to authorized users', function () {
    Artisan::call('db:seed', ['--class' => PermissionsSeeder::class]);

    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'PDM',
        'name' => 'Permission Detail Land',
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
        'name' => 'Permission Detail Co',
        'slug' => 'permission-detail-co',
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
    $role->syncPermissions(['employees.view']);

    grantCompanyPermissions($user, $company, ['roles.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get("/organization/roles/{$role->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/role')
            ->has('permissions', count(ApplicationPermissionRegistry::definitions()))
            ->where('permissions', function ($permissions): bool {
                $employeesView = collect($permissions)->firstWhere('name', 'employees.view');

                return is_array($employeesView)
                    && ($employeesView['label'] ?? null) === 'View Employees'
                    && str_contains((string) ($employeesView['description'] ?? ''), 'view')
                    && ($employeesView['group'] ?? null) === 'Employees';
            }),
        );
});

test('users without roles.view cannot access role permission management page', function () {
    Artisan::call('db:seed', ['--class' => PermissionsSeeder::class]);

    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'PNV',
        'name' => 'Permission No View Land',
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
        'name' => 'Permission No View Co',
        'slug' => 'permission-no-view-co',
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

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get("/organization/roles/{$role->id}")
        ->assertForbidden();
});

test('application permission names remain stable and none are removed by seeding', function () {
    Artisan::call('db:seed', ['--class' => PermissionsSeeder::class]);

    foreach (ApplicationPermissionRegistry::names() as $name) {
        expect(Permission::query()->where('guard_name', 'web')->where('name', $name)->exists())->toBeTrue();
    }

    $registryNames = ApplicationPermissionRegistry::names();
    sort($registryNames);

    expect($registryNames)->toBe(array_values(array_unique($registryNames)));
});
