<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function createActivityIntelligenceCompany(string $suffix): Company
{
    $country = Country::query()->create([
        'code' => 'AI'.strtoupper($suffix),
        'name' => 'Activity Intelligence '.$suffix,
        'dial_code' => '+99'.$suffix,
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'A'.strtoupper($suffix).'D',
        'name' => 'Activity Currency '.$suffix,
        'symbol' => 'A'.$suffix,
        'is_active' => true,
    ]);

    return Company::query()->create([
        'name' => 'Activity Co '.$suffix,
        'slug' => 'activity-co-'.strtolower($suffix),
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);
}

function createActivityBranch(Company $company, string $code): Branch
{
    return Branch::query()->create([
        'company_id' => $company->id,
        'name' => 'Operations '.$code,
        'code' => $code,
        'address' => null,
        'city' => 'Abu Dhabi',
        'country' => 'UAE',
        'phone' => null,
        'email' => null,
        'is_headquarters' => false,
        'status' => 'active',
    ]);
}

test('activity logs present human readable intelligence and useful filter options', function () {
    $company = createActivityIntelligenceCompany('ONE');
    $user = User::factory()->create(['name' => 'Audit Manager']);
    grantCompanyPermissions($user, $company, ['audit.view', 'branches.view']);

    $this->actingAs($user);
    $branch = createActivityBranch($company, 'OPS');

    $this->get(route('organization.activity-logs', [
        'date_from' => now()->toDateString(),
        'date_to' => now()->toDateString(),
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/activity-logs')
            ->has('logs', 1)
            ->where('logs.0.module_key', 'organization')
            ->where('logs.0.module_label', 'Organization')
            ->where('logs.0.importance', 'normal')
            ->where('logs.0.subject_label', $branch->name)
            ->where('logs.0.headline', 'Audit Manager created Branch '.$branch->name)
            ->where('logs.0.record_url', route('organization.branches.show', $branch))
            ->where('summary.total', 1)
            ->where('summary.users', 1)
            ->where('summary.important', 0)
            ->where('summary.critical', 0)
            ->where('modules.0.key', 'organization')
            ->where('users.0.id', $user->id)
            ->where('users.0.name', 'Audit Manager'),
        );
});

test('activity log filters by module user importance and searchable changed values', function () {
    $company = createActivityIntelligenceCompany('TWO');
    $firstUser = User::factory()->create(['name' => 'First Auditor']);
    $secondUser = User::factory()->create(['name' => 'Second Auditor']);
    grantCompanyPermissions($firstUser, $company, ['audit.view']);
    grantCompanyPermissions($secondUser, $company, ['audit.view']);

    $this->actingAs($firstUser);
    $firstBranch = createActivityBranch($company, 'ONE');
    $firstBranch->update(['city' => 'Dubai Marina']);

    $this->actingAs($secondUser);
    createActivityBranch($company, 'TWO');

    $this->actingAs($firstUser);

    $this->get(route('organization.activity-logs', [
        'module' => 'organization',
        'user_id' => (string) $firstUser->id,
        'importance' => 'normal',
        'q' => 'Dubai Marina',
        'date_from' => now()->toDateString(),
        'date_to' => now()->toDateString(),
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('logs', 1)
            ->where('logs.0.causer.id', $firstUser->id)
            ->where('logs.0.subject_id', $firstBranch->id)
            ->where('logs.0.headline', 'First Auditor changed City on Branch '.$firstBranch->name)
            ->where('filters.module', 'organization')
            ->where('filters.user_id', (string) $firstUser->id)
            ->where('filters.importance', 'normal')
            ->where('filters.q', 'Dubai Marina')
            ->where('pagination.total', 1),
        );
});

test('audit permission does not grant a record link without the subject view permission', function () {
    $company = createActivityIntelligenceCompany('THREE');
    $user = User::factory()->create(['name' => 'Audit Only']);
    grantCompanyPermissions($user, $company, ['audit.view']);

    $this->actingAs($user);
    $branch = createActivityBranch($company, 'SEC');

    $this->get(route('organization.activity-logs', [
        'date_from' => now()->toDateString(),
        'date_to' => now()->toDateString(),
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('logs', 1)
            ->where('logs.0.subject_id', $branch->id)
            ->where('logs.0.record_url', null),
        );
});

test('activity log intelligence remains isolated to the active company', function () {
    $company = createActivityIntelligenceCompany('FOUR');
    $otherCompany = createActivityIntelligenceCompany('FIVE');
    $user = User::factory()->create(['name' => 'Tenant Auditor']);
    grantCompanyPermissions($user, $company, ['audit.view']);

    $this->actingAs($user);
    $visibleBranch = createActivityBranch($company, 'VIS');
    $hiddenBranch = createActivityBranch($otherCompany, 'HID');

    $this->get(route('organization.activity-logs', [
        'date_from' => now()->toDateString(),
        'date_to' => now()->toDateString(),
    ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('logs', 1)
            ->where('logs.0.subject_id', $visibleBranch->id)
            ->where('pagination.total', 1),
        );

    expect($visibleBranch->company_id)->toBe($company->id)
        ->and($hiddenBranch->company_id)->toBe($otherCompany->id);
});
