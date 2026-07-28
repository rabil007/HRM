<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;
use App\Support\Notifications\ResolveTemplatePushRecipients;
use Illuminate\Support\Facades\DB;

/**
 * @return array{company: Company, otherCompany: Company}
 */
function makePushRecipientCompanies(): array
{
    $makeCompany = function (string $prefix): Company {
        $code = $prefix.fake()->unique()->numerify('##');
        $country = Country::query()->create([
            'code' => $code,
            'name' => "{$prefix}land",
            'dial_code' => '+971',
            'is_active' => true,
        ]);
        $currency = Currency::query()->create([
            'code' => $code,
            'name' => "{$prefix} Currency",
            'symbol' => 'P$',
            'is_active' => true,
        ]);

        return Company::query()->create([
            'name' => "{$prefix} Co",
            'slug' => strtolower($prefix).'-'.fake()->unique()->numerify('####'),
            'working_days' => [1, 2, 3, 4, 5],
            'country_id' => $country->id,
            'currency_id' => $currency->id,
            'timezone' => 'Asia/Dubai',
            'payroll_cycle' => 'monthly',
            'status' => 'active',
        ]);
    };

    return [
        'company' => $makeCompany('PR'),
        'otherCompany' => $makeCompany('PX'),
    ];
}

test('resolves template TO and CC users once by users.email', function () {
    ['company' => $company] = makePushRecipientCompanies();

    $toUser = User::factory()->create(['email' => 'to@example.test', 'status' => 'active']);
    $ccUser = User::factory()->create(['email' => 'cc@example.test', 'status' => 'active']);

    grantCompanyPermissions($toUser, $company, ['documents.view']);
    grantCompanyPermissions($ccUser, $company, ['documents.view']);

    $users = app(ResolveTemplatePushRecipients::class)->handle($company, [
        'to@example.test',
        'cc@example.test',
        'TO@example.test',
    ]);

    expect($users)->toHaveCount(2)
        ->and($users->pluck('id')->all())->toContain($toUser->id, $ccUser->id);
});

test('resolves through employee work_email and personal_email with linked user_id', function () {
    ['company' => $company] = makePushRecipientCompanies();

    $workUser = User::factory()->create(['email' => 'work-user@example.test', 'status' => 'active']);
    $personalUser = User::factory()->create(['email' => 'personal-user@example.test', 'status' => 'active']);

    grantCompanyPermissions($workUser, $company, ['documents.view']);
    grantCompanyPermissions($personalUser, $company, ['documents.view']);

    Employee::factory()->forCompany($company)->create([
        'user_id' => $workUser->id,
        'work_email' => 'Work.Alias@example.test',
        'status' => 'active',
    ]);
    Employee::factory()->forCompany($company)->create([
        'user_id' => $personalUser->id,
        'personal_email' => 'Personal.Alias@example.test',
        'status' => 'active',
    ]);

    $users = app(ResolveTemplatePushRecipients::class)->handle($company, [
        'work.alias@example.test',
        'personal.alias@example.test',
    ]);

    expect($users->pluck('id')->all())->toContain($workUser->id, $personalUser->id);
});

test('employee email without linked user_id is skipped', function () {
    ['company' => $company] = makePushRecipientCompanies();

    Employee::factory()->forCompany($company)->create([
        'user_id' => null,
        'work_email' => 'orphan@example.test',
        'status' => 'active',
    ]);

    $users = app(ResolveTemplatePushRecipients::class)->handle($company, [
        'orphan@example.test',
    ]);

    expect($users)->toHaveCount(0);
});

test('same email in another company does not leak', function () {
    ['company' => $company, 'otherCompany' => $otherCompany] = makePushRecipientCompanies();

    $foreignUser = User::factory()->create(['email' => 'shared@example.test', 'status' => 'active']);
    grantCompanyPermissions($foreignUser, $otherCompany, ['documents.view']);

    Employee::factory()->forCompany($otherCompany)->create([
        'user_id' => $foreignUser->id,
        'work_email' => 'shared@example.test',
        'status' => 'active',
    ]);

    $users = app(ResolveTemplatePushRecipients::class)->handle($company, [
        'shared@example.test',
    ]);

    expect($users)->toHaveCount(0);
});

test('inactive membership, inactive company, inactive user, and missing permission are skipped', function () {
    ['company' => $company] = makePushRecipientCompanies();

    $inactiveMembership = User::factory()->create(['email' => 'inactive-member@example.test', 'status' => 'active']);
    DB::table('company_user')->insert([
        'company_id' => $company->id,
        'user_id' => $inactiveMembership->id,
        'status' => 'inactive',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $inactiveUser = User::factory()->create(['email' => 'inactive-user@example.test', 'status' => 'inactive']);
    grantCompanyPermissions($inactiveUser, $company, ['documents.view']);
    $inactiveUser->update(['status' => 'inactive']);

    $noPermission = User::factory()->create(['email' => 'no-perm@example.test', 'status' => 'active']);
    grantCompanyPermissions($noPermission, $company, ['announcements.view']);

    $users = app(ResolveTemplatePushRecipients::class)->handle($company, [
        'inactive-member@example.test',
        'inactive-user@example.test',
        'no-perm@example.test',
    ]);

    expect($users)->toHaveCount(0);

    $company->update(['status' => 'inactive']);
    $activeUser = User::factory()->create(['email' => 'active@example.test', 'status' => 'active']);
    grantCompanyPermissions($activeUser, $company, ['documents.view']);

    expect(app(ResolveTemplatePushRecipients::class)->handle($company->fresh(), [
        'active@example.test',
    ]))->toHaveCount(0);
});
