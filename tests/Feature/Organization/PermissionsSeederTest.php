<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Support\Authorization\ApplicationPermissionRegistry;
use Illuminate\Support\Facades\Artisan;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array{company: Company, role: Role}
 */
function makeRecruitmentPermissionRole(string $name, string $slug, string $code): array
{
    $country = Country::query()->create([
        'code' => $code,
        'name' => "{$name} Land",
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
        'name' => $name,
        'slug' => $slug,
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Recruiter Role',
        'guard_name' => 'web',
    ]);

    return ['company' => $company, 'role' => $role];
}

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
    expect(Permission::query()->where('name', 'reports.leave.view')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'reports.leave.export')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'reports.leave_balance.view')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'reports.leave_balance.export')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'crew_operations.settings.view')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'crew_operations.settings.update')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'recruitment.requirements.submit')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'recruitment.requirements.approve')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'recruitment.requirements.request_deadline_extension')->exists())->toBeTrue();
    expect(Permission::query()->where('name', 'recruitment.requirements.request_headcount_revision')->exists())->toBeTrue();

    expect(Permission::query()->where('name', 'company.settings.view')->exists())->toBeFalse();
    expect(Permission::query()->where('name', 'company.settings.update')->exists())->toBeFalse();
    expect(Permission::query()->where('name', 'company.document-settings.view')->exists())->toBeFalse();
    expect(Permission::query()->where('name', 'company.document-settings.update')->exists())->toBeFalse();
    expect(Permission::query()->where('name', 'platform.settings.view')->exists())->toBeFalse();
    expect(Permission::query()->where('name', 'platform.settings.update')->exists())->toBeFalse();
    expect(Permission::query()->where('name', 'crew_operations.rank_policies.view')->exists())->toBeFalse();
    expect(Permission::query()->where('name', 'crew_operations.rank_policies.update')->exists())->toBeFalse();
    expect(Permission::query()->where('name', 'bulk_documents.signatures.review')->exists())->toBeFalse();
});

test('permission metadata follows current module categories without changing names', function () {
    $groups = [
        'settings.master-data.countries.view' => 'Master Data',
        'settings.master-data.banks.view' => 'Master Data',
        'settings.master-data.clients.update' => 'Master Data',
        'settings.master-data.company-visa-types.view' => 'Master Data',
        'settings.master-data.document-types.view' => 'Documents',
        'settings.master-data.vessels.view' => 'Settings',
        'settings.security.view' => 'Settings',
        'settings.appearance.view' => 'Settings',
        'settings.integrations.hikvision.view' => 'Integrations',
        'crew_operations.vessels.view' => 'Crew Operations',
        'recruitment.requirements.submit' => 'Recruitment',
        'recruitment.requirements.approve' => 'Recruitment',
        'recruitment.requirements.request_deadline_extension' => 'Recruitment',
        'recruitment.requirements.request_headcount_revision' => 'Recruitment',
    ];

    foreach ($groups as $name => $group) {
        expect(ApplicationPermissionRegistry::find($name)['group'] ?? null)->toBe($group);
    }

    expect(ApplicationPermissionRegistry::names())->toContain(...array_keys($groups));

    foreach (ApplicationPermissionRegistry::definitions() as $definition) {
        if (! str_starts_with($definition['name'], 'settings.master-data.')) {
            continue;
        }

        $area = explode('.', $definition['name'])[2];
        $expectedGroup = match ($area) {
            'document-types' => 'Documents',
            'vessels' => 'Settings',
            default => 'Master Data',
        };

        expect($definition['group'])->toBe($expectedGroup);
    }
});

test('roles page does not expose a platform or rank policies permission group after seeding', function () {
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
    $role->syncPermissions([
        'settings.application.view',
        'settings.application.update',
        'settings.master-data.vessels.view',
    ]);

    $assignedBefore = $role->permissions()->pluck('name')->sort()->values()->all();
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);
    expect($role->fresh()->permissions()->pluck('name')->sort()->values()->all())->toBe($assignedBefore);

    grantCompanyPermissions($user, $company, ['roles.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get("/organization/roles/{$role->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/role')
            ->has('permissions')
            ->where('permissions', function ($permissions) {
                $options = collect($permissions)->keyBy('name');
                $names = $options->keys();

                return $names->contains('settings.application.view')
                    && $names->contains('settings.application.update')
                    && $names->contains('settings.master-data.vessels.view')
                    && $options->get('settings.master-data.countries.view')['group'] === 'Master Data'
                    && $options->get('settings.master-data.document-types.view')['group'] === 'Documents'
                    && $options->get('settings.integrations.hikvision.view')['group'] === 'Integrations'
                    && ! $names->contains('platform.settings.view')
                    && ! $names->contains('platform.settings.update')
                    && ! $names->contains('crew_operations.rank_policies.view')
                    && ! $names->contains('crew_operations.rank_policies.update');
            }),
        );
});

test('roles page exposes all recruitment requirement permissions under Recruitment group after seeding', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);

    $user = User::factory()->create();
    $country = Country::query()->create([
        'code' => 'RPR',
        'name' => 'Recruitment Role Land',
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
        'name' => 'Recruitment Role Co',
        'slug' => 'recruitment-role-co-permissions',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $role = Role::query()->create([
        'company_id' => $company->id,
        'name' => 'Recruiter Role',
        'guard_name' => 'web',
    ]);

    grantCompanyPermissions($user, $company, ['roles.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get("/organization/roles/{$role->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/role')
            ->has('permissions')
            ->where('permissions', function ($permissions) {
                $options = collect($permissions)->keyBy('name');
                $recruitmentOptions = collect($permissions)->where('group', 'Recruitment');

                return $recruitmentOptions->count() === 11
                    && $options->has('recruitment.requirements.view')
                    && $options->has('recruitment.requirements.create')
                    && $options->has('recruitment.requirements.update')
                    && $options->has('recruitment.requirements.close')
                    && $options->has('recruitment.requirements.cancel')
                    && $options->has('recruitment.requirements.reopen')
                    && $options->has('recruitment.requirements.attachments.download')
                    && $options->has('recruitment.requirements.submit')
                    && $options->get('recruitment.requirements.submit')['label'] === 'Submit Recruitment Requirements'
                    && $options->get('recruitment.requirements.submit')['group'] === 'Recruitment'
                    && $options->has('recruitment.requirements.approve')
                    && $options->get('recruitment.requirements.approve')['label'] === 'Approve Recruitment Requirements'
                    && $options->get('recruitment.requirements.approve')['group'] === 'Recruitment'
                    && $options->has('recruitment.requirements.request_deadline_extension')
                    && $options->get('recruitment.requirements.request_deadline_extension')['label'] === 'Request Requirement Deadline Extensions'
                    && $options->get('recruitment.requirements.request_deadline_extension')['group'] === 'Recruitment'
                    && $options->has('recruitment.requirements.request_headcount_revision')
                    && $options->get('recruitment.requirements.request_headcount_revision')['label'] === 'Request Requirement Headcount Revisions'
                    && $options->get('recruitment.requirements.request_headcount_revision')['group'] === 'Recruitment';
            }),
        );
});

test('permissions seeder does not grant request_deadline_extension to roles that only have approve', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);

    ['company' => $company, 'role' => $role] = makeRecruitmentPermissionRole(
        'Approve Only Co',
        'approve-only-permissions',
        'AOR',
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role->syncPermissions(['recruitment.requirements.approve']);

    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    $names = $role->fresh()->permissions()->pluck('name');

    expect($names)->toContain('recruitment.requirements.approve')
        ->and($names)->not->toContain('recruitment.requirements.request_deadline_extension');
});

test('permissions seeder does not restore request_deadline_extension after an administrator removes it', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);

    ['company' => $company, 'role' => $role] = makeRecruitmentPermissionRole(
        'Revoked Deadline Co',
        'revoked-deadline-permissions',
        'RDC',
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role->syncPermissions([
        'recruitment.requirements.approve',
        'recruitment.requirements.request_deadline_extension',
    ]);
    $role->revokePermissionTo('recruitment.requirements.request_deadline_extension');

    $namesBeforeSeed = $role->fresh()->permissions()->pluck('name');

    expect($namesBeforeSeed)->toContain('recruitment.requirements.approve')
        ->and($namesBeforeSeed)->not->toContain('recruitment.requirements.request_deadline_extension');

    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    $names = $role->fresh()->permissions()->pluck('name');

    expect($names)->toContain('recruitment.requirements.approve')
        ->and($names)->not->toContain('recruitment.requirements.request_deadline_extension');
});

test('permissions seeder preserves an explicitly configured pair of approve and request_deadline_extension', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);

    ['company' => $company, 'role' => $role] = makeRecruitmentPermissionRole(
        'Both Deadline Co',
        'both-deadline-permissions',
        'BDC',
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role->syncPermissions([
        'recruitment.requirements.approve',
        'recruitment.requirements.request_deadline_extension',
    ]);

    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    $names = $role->fresh()->permissions()->pluck('name');

    expect($names)->toContain('recruitment.requirements.approve')
        ->and($names)->toContain('recruitment.requirements.request_deadline_extension');
});

test('permissions seeder does not grant request_headcount_revision to roles that only have approve', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);

    ['company' => $company, 'role' => $role] = makeRecruitmentPermissionRole(
        'Approve Only Headcount Co',
        'approve-only-headcount-permissions',
        'AHC',
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role->syncPermissions(['recruitment.requirements.approve']);

    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    $names = $role->fresh()->permissions()->pluck('name');

    expect($names)->toContain('recruitment.requirements.approve')
        ->and($names)->not->toContain('recruitment.requirements.request_headcount_revision');
});

test('permissions seeder does not restore request_headcount_revision after an administrator removes it', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);

    ['company' => $company, 'role' => $role] = makeRecruitmentPermissionRole(
        'Revoked Headcount Co',
        'revoked-headcount-permissions',
        'RHC',
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role->syncPermissions([
        'recruitment.requirements.approve',
        'recruitment.requirements.request_headcount_revision',
    ]);
    $role->revokePermissionTo('recruitment.requirements.request_headcount_revision');

    $namesBeforeSeed = $role->fresh()->permissions()->pluck('name');

    expect($namesBeforeSeed)->toContain('recruitment.requirements.approve')
        ->and($namesBeforeSeed)->not->toContain('recruitment.requirements.request_headcount_revision');

    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    $names = $role->fresh()->permissions()->pluck('name');

    expect($names)->toContain('recruitment.requirements.approve')
        ->and($names)->not->toContain('recruitment.requirements.request_headcount_revision');
});

test('permissions seeder preserves an explicitly configured pair of approve and request_headcount_revision', function () {
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);

    ['company' => $company, 'role' => $role] = makeRecruitmentPermissionRole(
        'Both Headcount Co',
        'both-headcount-permissions',
        'BHC',
    );

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
    $role->syncPermissions([
        'recruitment.requirements.approve',
        'recruitment.requirements.request_headcount_revision',
    ]);

    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\PermissionsSeeder']);
    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    $names = $role->fresh()->permissions()->pluck('name');

    expect($names)->toContain('recruitment.requirements.approve')
        ->and($names)->toContain('recruitment.requirements.request_headcount_revision');
});
