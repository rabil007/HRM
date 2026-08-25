<?php

use App\Models\NavigationFavorite;
use App\Models\User;
use App\Support\Navigation\NavigationDestinationCatalog;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

function favoriteUserWithCompany(array $permissions = ['employees.view']): array
{
    $user = User::factory()->create();
    ['company' => $company] = makeDocumentFixtures();
    grantCompanyPermissions($user, $company, $permissions);

    return [$user, $company];
}

function addFavorite(User $user, int $companyId, string $key, array $extra = [])
{
    return test()->actingAs($user)
        ->withSession(['current_company_id' => $companyId])
        ->from('/dashboard')
        ->post(route('favorites.store'), array_merge(['key' => $key], $extra));
}

function forgetFavoriteUserPermissionState(User $user): void
{
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function removeFavorite(User $user, int $companyId, string $key, array $extra = [])
{
    return test()->actingAs($user)
        ->withSession(['current_company_id' => $companyId])
        ->from('/dashboard')
        ->delete(route('favorites.destroy', ['destination' => $key]), $extra);
}

test('guests cannot manage favorites', function () {
    $this->post(route('favorites.store'), ['key' => 'employees'])->assertRedirect();
    $this->delete(route('favorites.destroy', ['destination' => 'employees']))->assertRedirect();
});

test('authenticated users can add a known favorite', function () {
    [$user, $company] = favoriteUserWithCompany(['employees.view']);

    addFavorite($user, $company->id, 'employees')->assertRedirect('/dashboard');

    expect($user->navigationFavorites()->pluck('destination_key')->all())->toBe(['employees']);
});

test('duplicate favorites are idempotent', function () {
    [$user, $company] = favoriteUserWithCompany(['employees.view']);

    addFavorite($user, $company->id, 'employees')->assertRedirect('/dashboard');
    addFavorite($user, $company->id, 'employees')->assertRedirect('/dashboard');

    expect($user->navigationFavorites()->count())->toBe(1);
});

test('users can remove their own favorites', function () {
    [$user, $company] = favoriteUserWithCompany(['employees.view']);
    addFavorite($user, $company->id, 'employees')->assertRedirect('/dashboard');

    removeFavorite($user, $company->id, 'employees')->assertRedirect('/dashboard');

    expect($user->navigationFavorites()->count())->toBe(0);
});

test('users cannot manipulate another users favorites', function () {
    [$owner, $company] = favoriteUserWithCompany(['employees.view']);
    addFavorite($owner, $company->id, 'employees')->assertRedirect('/dashboard');

    $stranger = User::factory()->create();
    grantCompanyPermissions($stranger, $company, ['employees.view']);

    removeFavorite($stranger, $company->id, 'employees')->assertRedirect('/dashboard');
    addFavorite($stranger, $company->id, 'employees', ['user_id' => $owner->id])
        ->assertRedirect('/dashboard')
        ->assertSessionHasErrors('user_id');

    expect($owner->navigationFavorites()->pluck('destination_key')->all())->toBe(['employees'])
        ->and($stranger->navigationFavorites()->count())->toBe(0);
});

test('unknown destination keys are rejected', function () {
    [$user, $company] = favoriteUserWithCompany();

    addFavorite($user, $company->id, 'employee.record.12')
        ->assertRedirect('/dashboard')
        ->assertSessionHasErrors('key');
    addFavorite($user, $company->id, 'not-a-destination')
        ->assertRedirect('/dashboard')
        ->assertSessionHasErrors('key');

    expect($user->navigationFavorites()->count())->toBe(0);
});

test('client supplied urls are not accepted', function () {
    [$user, $company] = favoriteUserWithCompany();

    addFavorite($user, $company->id, 'employees', [
        'url' => '/organization/employees/99',
        'href' => 'https://evil.example/phish',
    ])->assertRedirect('/dashboard')
        ->assertSessionHasErrors(['url', 'href']);

    expect($user->navigationFavorites()->count())->toBe(0);
});

test('view-only destinations can still be favorited', function () {
    [$user, $company] = favoriteUserWithCompany(['employees.view']);

    addFavorite($user, $company->id, 'employees')->assertRedirect('/dashboard');

    expect($user->can('employees.create'))->toBeFalse()
        ->and($user->navigationFavorites()->pluck('destination_key')->all())->toBe(['employees']);
});

test('inaccessible catalog destinations cannot be added', function () {
    [$user, $company] = favoriteUserWithCompany(['departments.view']);

    addFavorite($user, $company->id, 'employees')->assertForbidden();
    addFavorite($user, $company->id, 'platform.logs')->assertForbidden();

    expect($user->navigationFavorites()->count())->toBe(0);
});

test('platform access does not grant tenant module favorites', function () {
    [$user, $company] = favoriteUserWithCompany(['departments.view']);
    grantPlatformAccess($user, 'view');

    addFavorite($user, $company->id, 'employees')->assertForbidden();
    addFavorite($user, $company->id, 'platform.logs')->assertRedirect('/dashboard');

    expect($user->navigationFavorites()->pluck('destination_key')->all())->toBe(['platform.logs']);
});

test('adding a favorite does not change company permissions', function () {
    [$user, $company] = favoriteUserWithCompany(['employees.view']);

    addFavorite($user, $company->id, 'employees')->assertRedirect('/dashboard');

    app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

    expect($user->getAllPermissions()->pluck('name')->sort()->values()->all())
        ->toBe(['employees.view']);
});

test('favorites survive a company switch and stay in shared keys', function () {
    $user = User::factory()->create();
    ['company' => $companyA] = makeDocumentFixtures();
    ['company' => $companyB] = makeDocumentFixtures();
    grantCompanyPermissions($user, $companyA, ['employees.view']);
    grantCompanyPermissions($user, $companyB, ['departments.view']);

    addFavorite($user, $companyA->id, 'employees')->assertRedirect('/dashboard');

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('favorite_destination_keys', ['employees'])
            ->where('current_company_id', $companyA->id)
        );

    forgetFavoriteUserPermissionState($user);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyB->id])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('favorite_destination_keys', ['employees'])
            ->where('current_company_id', $companyB->id)
        );

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyB->id])
        ->get('/organization/employees')
        ->assertForbidden();

    addFavorite($user, $companyB->id, 'employees')->assertForbidden();

    expect($user->navigationFavorites()->count())->toBe(1);

    forgetFavoriteUserPermissionState($user);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('favorite_destination_keys', ['employees'])
            ->where('current_company_id', $companyA->id)
        );

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->get('/organization/employees')
        ->assertOk();
});

test('users viewers without create can favorite users', function () {
    [$user, $company] = favoriteUserWithCompany(['users.view']);

    addFavorite($user, $company->id, 'organization.users')->assertRedirect('/dashboard');

    expect($user->can('users.create'))->toBeFalse()
        ->and($user->navigationFavorites()->pluck('destination_key')->all())->toBe(['organization.users']);
});

test('payroll-only users can favorite payroll but not employees', function () {
    [$user, $company] = favoriteUserWithCompany(['payroll.periods.view']);

    addFavorite($user, $company->id, 'payroll')->assertRedirect('/dashboard');
    addFavorite($user, $company->id, 'employees')->assertForbidden();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/organization/employees')
        ->assertForbidden();

    expect($user->navigationFavorites()->pluck('destination_key')->all())->toBe(['payroll']);
});

test('tenant users without platform access cannot favorite platform destinations', function () {
    [$user, $company] = favoriteUserWithCompany([
        'employees.view',
        'documents.view',
        'users.view',
        'roles.view',
        'payroll.periods.view',
        'companies.view',
    ]);

    expect($user->platform_access)->toBeNull();

    addFavorite($user, $company->id, 'platform.logs')->assertForbidden();
    addFavorite($user, $company->id, 'platform.jobs')->assertForbidden();
    addFavorite($user, $company->id, 'platform.database')->assertForbidden();
    addFavorite($user, $company->id, 'employees')->assertRedirect('/dashboard');
});

test('users without module navigation permissions can only favorite dashboard', function () {
    [$user, $company] = favoriteUserWithCompany([]);

    addFavorite($user, $company->id, 'dashboard')->assertRedirect('/dashboard');
    addFavorite($user, $company->id, 'employees')->assertForbidden();
    addFavorite($user, $company->id, 'payroll')->assertForbidden();

    expect($user->navigationFavorites()->pluck('destination_key')->all())->toBe(['dashboard']);
});

test('renamed catalog keys remain stored and can be removed', function () {
    [$user, $company] = favoriteUserWithCompany(['employees.view']);

    NavigationFavorite::factory()->create([
        'user_id' => $user->id,
        'destination_key' => 'legacy.removed',
        'position' => 1,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('favorite_destination_keys', ['legacy.removed'])
        );

    removeFavorite($user, $company->id, 'legacy.removed')->assertRedirect('/dashboard');

    expect($user->navigationFavorites()->count())->toBe(0);
});

test('favorite count is capped', function () {
    [$user, $company] = favoriteUserWithCompany(['employees.view']);

    foreach (range(1, NavigationFavorite::MAX_PER_USER) as $position) {
        NavigationFavorite::query()->create([
            'user_id' => $user->id,
            'destination_key' => "stale.{$position}",
            'position' => $position,
        ]);
    }

    addFavorite($user, $company->id, 'employees')
        ->assertRedirect('/dashboard')
        ->assertSessionHasErrors('key');

    expect($user->navigationFavorites()->count())->toBe(NavigationFavorite::MAX_PER_USER);
});

test('catalog keys used by the product examples exist', function () {
    expect(NavigationDestinationCatalog::keys())->toContain(
        'employees',
        'documents',
        'crew.current',
        'crew.planning',
        'crew.vessels',
        'crew.vessel-manning',
        'leave.requests',
        'payroll',
        'attendance.records',
    );
});

test('inertia share loads favorite keys in one bounded query', function () {
    [$user, $company] = favoriteUserWithCompany(['employees.view']);
    addFavorite($user, $company->id, 'employees')->assertRedirect('/dashboard');

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get('/dashboard')
        ->assertOk();

    $favoriteQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'navigation_favorites'))
        ->values();

    expect($favoriteQueries)->toHaveCount(1)
        ->and(strtolower($favoriteQueries[0]['query']))->toContain('destination_key')
        ->and(strtolower($favoriteQueries[0]['query']))->toContain('limit');
});
