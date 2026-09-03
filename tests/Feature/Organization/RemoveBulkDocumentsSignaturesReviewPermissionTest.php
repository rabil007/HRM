<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

test('migration detaches and deletes bulk_documents.signatures.review', function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $permission = Permission::query()->firstOrCreate([
        'name' => 'bulk_documents.signatures.review',
        'guard_name' => 'web',
    ]);
    $keep = Permission::query()->firstOrCreate([
        'name' => 'bulk_documents.view',
        'guard_name' => 'web',
    ]);

    $country = Country::query()->create([
        'code' => 'LSR',
        'name' => 'Legacy Sign Review Land',
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
        'name' => 'Legacy Sign Co',
        'slug' => 'legacy-sign-review-co',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Legacy Signature Reviewer',
        'guard_name' => 'web',
    ]);
    $user = User::factory()->create();
    $user->companies()->syncWithoutDetaching([$company->id => ['status' => 'active']]);

    $roleHasPermissions = config('permission.table_names.role_has_permissions');
    $modelHasPermissions = config('permission.table_names.model_has_permissions');
    $pivotPermission = config('permission.column_names.permission_pivot_key') ?? 'permission_id';
    $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';
    $teamKey = config('permission.column_names.team_foreign_key');

    DB::table($roleHasPermissions)->insert([
        $pivotPermission => $permission->id,
        $pivotRole => $role->id,
    ]);
    DB::table($roleHasPermissions)->insert([
        $pivotPermission => $keep->id,
        $pivotRole => $role->id,
    ]);

    $direct = [
        $pivotPermission => $permission->id,
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->id,
    ];

    if ($teamKey) {
        $direct[$teamKey] = $company->id;
    }

    DB::table($modelHasPermissions)->insert($direct);

    /** @var object{up(): void} $migration */
    $migration = require database_path('migrations/2026_09_03_105549_remove_bulk_documents_signatures_review_permission.php');
    $migration->up();

    expect(Permission::query()->where('name', 'bulk_documents.signatures.review')->exists())->toBeFalse()
        ->and(Permission::query()->where('name', 'bulk_documents.view')->exists())->toBeTrue()
        ->and(DB::table($roleHasPermissions)->where($pivotRole, $role->id)->where($pivotPermission, $keep->id)->exists())->toBeTrue()
        ->and(DB::table($roleHasPermissions)->where($pivotPermission, $permission->id)->exists())->toBeFalse()
        ->and(DB::table($modelHasPermissions)->where($pivotPermission, $permission->id)->exists())->toBeFalse();
});
