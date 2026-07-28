<?php

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Support\EmployeeDocuments\DocumentExpiryStatus;
use Illuminate\Support\Facades\DB;

/**
 * @return array{company: Company, otherCompany: Company, user: User, outsider: User}
 */
function makeOpenComplianceFixtures(): array
{
    $makeCompany = function (string $prefix, string $status = 'active'): Company {
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
            'symbol' => 'C$',
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
            'status' => $status,
        ]);
    };

    $company = $makeCompany('DC');
    $otherCompany = $makeCompany('DX');
    $user = User::factory()->create(['status' => 'active']);
    $outsider = User::factory()->create(['status' => 'active']);

    return compact('company', 'otherCompany', 'user', 'outsider');
}

test('recipient with membership and documents.view opens compliance page for that company', function () {
    ['company' => $company, 'otherCompany' => $otherCompany, 'user' => $user] = makeOpenComplianceFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);
    grantCompanyPermissions($user, $otherCompany, ['documents.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $otherCompany->id])
        ->get(route('notifications.documents.compliance.open', $company))
        ->assertRedirect(route('organization.documents', [
            'expiry' => DocumentExpiryStatus::Expiring30->value,
        ]));

    expect(session('current_company_id'))->toBe($company->id);
});

test('guest is redirected to login', function () {
    ['company' => $company] = makeOpenComplianceFixtures();

    $this->get(route('notifications.documents.compliance.open', $company))
        ->assertRedirect();
});

test('user without membership is rejected', function () {
    ['company' => $company, 'outsider' => $outsider] = makeOpenComplianceFixtures();

    $this->actingAs($outsider)
        ->get(route('notifications.documents.compliance.open', $company))
        ->assertForbidden();
});

test('user with inactive membership is rejected', function () {
    ['company' => $company, 'user' => $user] = makeOpenComplianceFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);
    DB::table('company_user')
        ->where('company_id', $company->id)
        ->where('user_id', $user->id)
        ->update(['status' => 'inactive']);

    $this->actingAs($user)
        ->get(route('notifications.documents.compliance.open', $company))
        ->assertForbidden();
});

test('user without documents.view is rejected', function () {
    ['company' => $company, 'user' => $user] = makeOpenComplianceFixtures();

    grantCompanyPermissions($user, $company, ['announcements.view']);

    $this->actingAs($user)
        ->get(route('notifications.documents.compliance.open', $company))
        ->assertForbidden();
});

test('inactive company is rejected', function () {
    ['company' => $company, 'user' => $user] = makeOpenComplianceFixtures();

    grantCompanyPermissions($user, $company, ['documents.view']);
    $company->update(['status' => 'inactive']);

    $this->actingAs($user)
        ->get(route('notifications.documents.compliance.open', $company))
        ->assertForbidden();
});
