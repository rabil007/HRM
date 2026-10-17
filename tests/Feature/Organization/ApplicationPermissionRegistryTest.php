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
    $names = [];

    foreach (ApplicationPermissionRegistry::definitions() as $permission) {
        expect(isset($names[$permission['name']]))->toBeFalse();
        $names[$permission['name']] = true;
        expect($permission)->toHaveKeys(['name', 'label', 'description', 'group'])
            ->and($permission['name'])->toBeString()->not->toBeEmpty()
            ->and($permission['label'])->toBeString()->not->toBeEmpty()
            ->and($permission['description'])->toBeString()->not->toBeEmpty()
            ->and($permission['group'])->toBeString()->not->toBeEmpty()
            ->and(strlen($permission['description']))->toBeGreaterThan(20);

        foreach ($placeholders as $placeholder) {
            expect(str_contains(strtolower($permission['description']), strtolower($placeholder)))->toBeFalse();
        }
    }
});

test('users.impersonate is registered once with Login as User metadata', function () {
    $names = ApplicationPermissionRegistry::names();

    expect($names)->toContain('users.impersonate')
        ->and(array_values(array_filter($names, fn (string $name): bool => $name === 'users.impersonate')))
        ->toHaveCount(1);

    $definition = ApplicationPermissionRegistry::find('users.impersonate');

    expect($definition)->not->toBeNull()
        ->and($definition['name'])->toBe('users.impersonate')
        ->and($definition['label'])->toBe('Login as User')
        ->and($definition['group'])->toBe('Users')
        ->and($definition['description'])->toContain('impersonate');
});

test('application permission catalog source uses unique explicit numeric keys', function () {
    $source = file_get_contents(base_path('app/Support/Authorization/ApplicationPermissionDefinitions.php'));
    expect($source)->not->toBeFalse();

    preg_match_all(
        "/^\s*(?:(\d+)\s*=>\s*)?\[\s*\n\s*'name'\s*=>\s*'([^']+)'/m",
        $source,
        $matches,
        PREG_SET_ORDER,
    );

    expect($matches)->not->toBeEmpty();

    $keys = [];
    $unkeyed = [];
    $duplicateKeys = [];

    foreach ($matches as $match) {
        $name = $match[2];

        if ($match[1] === '') {
            $unkeyed[] = $name;

            continue;
        }

        $key = (int) $match[1];

        if (isset($keys[$key])) {
            $duplicateKeys[$key] = [$keys[$key], $name];
        }

        $keys[$key] = $name;
    }

    expect($unkeyed)->toBe([])
        ->and($duplicateKeys)->toBe([])
        ->and(count($keys))->toBe(count($matches))
        ->and(array_values($keys))->toContain('users.impersonate');
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
                $impersonate = collect($permissions)->firstWhere('name', 'users.impersonate');

                return is_array($employeesView)
                    && ($employeesView['label'] ?? null) === 'View Employees'
                    && str_contains((string) ($employeesView['description'] ?? ''), 'view')
                    && ($employeesView['group'] ?? null) === 'Employees'
                    && is_array($impersonate)
                    && ($impersonate['label'] ?? null) === 'Login as User'
                    && ($impersonate['group'] ?? null) === 'Users';
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
