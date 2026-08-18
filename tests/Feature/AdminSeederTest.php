<?php

use App\Enums\PlatformAccess;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\PermissionsSeeder;
use Illuminate\Support\Facades\Hash;

function createTestCompany(string $name = 'Acme Corp', string $slug = 'acme-corp'): Company
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
        'name' => $name,
        'slug' => $slug,
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

test('admin seeder creates demo admin in local and testing environments', function () {
    createTestCompany();
    $this->seed(PermissionsSeeder::class);

    $this->seed(AdminSeeder::class);

    $admin = User::query()->where('email', 'admin@example.com')->first();

    expect($admin)->not->toBeNull()
        ->and($admin->platform_access)->toBe(PlatformAccess::Manage)
        ->and(Hash::check('password', $admin->password))->toBeTrue()
        ->and($admin->hasRole('Owner'))->toBeTrue();
});

test('admin seeder safely returns and does not create demo admin in production', function () {
    createTestCompany();
    $this->seed(PermissionsSeeder::class);

    $this->app['env'] = 'production';

    $this->artisan('db:seed', ['--class' => AdminSeeder::class, '--force' => true])
        ->assertSuccessful();

    $admin = User::query()->where('email', 'admin@example.com')->first();

    expect($admin)->toBeNull();
});

test('admin seeder in production does not reset an existing administrator account', function () {
    $company = createTestCompany();
    $this->seed(PermissionsSeeder::class);

    $customPasswordHash = Hash::make('CustomProductionSecret123!');
    $existing = User::factory()->create([
        'email' => 'admin@example.com',
        'password' => $customPasswordHash,
        'platform_access' => PlatformAccess::View,
        'company_id' => $company->id,
    ]);

    $this->app['env'] = 'production';

    $this->artisan('db:seed', ['--class' => AdminSeeder::class, '--force' => true])
        ->assertSuccessful();

    $refreshed = $existing->fresh();
    expect($refreshed->password)->toBe($customPasswordHash)
        ->and($refreshed->platform_access)->toBe(PlatformAccess::View)
        ->and(Hash::check('password', $refreshed->password))->toBeFalse();
});

test('permissions seeder does not create administrator accounts or grant platform access', function () {
    $user = User::factory()->create(['email' => 'user@example.com']);

    $this->seed(PermissionsSeeder::class);

    expect(User::query()->where('email', 'admin@example.com')->exists())->toBeFalse()
        ->and($user->fresh()->platform_access)->toBeNull();
});
