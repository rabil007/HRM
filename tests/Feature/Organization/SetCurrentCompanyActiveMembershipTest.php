<?php

use App\Http\Middleware\SetCurrentCompany;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * @return array{user: User, activeCompany: Company, inactiveMembershipCompany: Company}
 */
function makeActiveMembershipFixtures(): array
{
    $country = Country::query()->create([
        'code' => 'AM'.fake()->unique()->numerify('##'),
        'name' => 'Active Membershipland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'AM'.fake()->unique()->numerify('##'),
        'name' => 'Active Membership Currency',
        'symbol' => 'A$',
        'is_active' => true,
    ]);

    $activeCompany = Company::query()->create([
        'name' => 'Active Membership Co',
        'slug' => 'am-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $inactiveMembershipCompany = Company::query()->create([
        'name' => 'Inactive Membership Co',
        'slug' => 'im-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'status' => 'active',
        'company_id' => $activeCompany->id,
    ]);

    DB::table('company_user')->insert([
        [
            'company_id' => $activeCompany->id,
            'user_id' => $user->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'company_id' => $inactiveMembershipCompany->id,
            'user_id' => $user->id,
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    return [
        'user' => $user,
        'activeCompany' => $activeCompany,
        'inactiveMembershipCompany' => $inactiveMembershipCompany,
    ];
}

test('inactive company membership cannot become current company', function () {
    ['user' => $user, 'activeCompany' => $activeCompany, 'inactiveMembershipCompany' => $inactiveCompany] = makeActiveMembershipFixtures();

    $request = Request::create('/dashboard', 'GET');
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('current_company_id', $inactiveCompany->id);

    (new SetCurrentCompany)->handle($request, fn ($req) => response('ok'));

    expect((int) $request->attributes->get('current_company_id'))->toBe((int) $activeCompany->id)
        ->and((int) $request->session()->get('current_company_id'))->toBe((int) $activeCompany->id)
        ->and((int) app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe((int) $activeCompany->id);
});

test('legacy home company works only when no pivot row exists', function () {
    $country = Country::query()->create([
        'code' => 'LH'.fake()->unique()->numerify('##'),
        'name' => 'Legacy Homeland',
        'dial_code' => '+971',
        'is_active' => true,
    ]);
    $currency = Currency::query()->create([
        'code' => 'LH'.fake()->unique()->numerify('##'),
        'name' => 'Legacy Home Currency',
        'symbol' => 'L$',
        'is_active' => true,
    ]);
    $company = Company::query()->create([
        'name' => 'Legacy Home Co',
        'slug' => 'lh-'.fake()->unique()->numerify('####'),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    $user = User::factory()->create([
        'status' => 'active',
        'company_id' => $company->id,
    ]);

    expect(DB::table('company_user')->where('user_id', $user->id)->where('company_id', $company->id)->exists())->toBeFalse();

    $request = Request::create('/dashboard', 'GET');
    $request->setUserResolver(fn () => $user);
    $request->setLaravelSession(app('session.store'));

    (new SetCurrentCompany)->handle($request, fn ($req) => response('ok'));

    expect((int) $request->attributes->get('current_company_id'))->toBe((int) $company->id);
});

test('inactive pivot for home company is not accepted as legacy access', function () {
    ['user' => $user, 'activeCompany' => $activeCompany, 'inactiveMembershipCompany' => $inactiveCompany] = makeActiveMembershipFixtures();

    $user->forceFill(['company_id' => $inactiveCompany->id])->save();

    $request = Request::create('/dashboard', 'GET');
    $request->setUserResolver(fn () => $user->fresh());
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('current_company_id', $inactiveCompany->id);

    (new SetCurrentCompany)->handle($request, fn ($req) => response('ok'));

    expect((int) $request->attributes->get('current_company_id'))->toBe((int) $activeCompany->id);
});
