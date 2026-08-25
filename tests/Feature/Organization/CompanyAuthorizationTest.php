<?php

use App\Enums\PlatformAccess;
use App\Models\Company;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

test('companies index lists only the active company', function () {
    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    grantCompanyPermissions($user, $companyA, ['companies.view']);
    grantCompanyPermissions($user, $companyB, ['companies.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->get('/organization/companies')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organization/companies')
            ->has('companies', 1)
            ->where('companies.0.id', $companyA->id)
            ->where('companies.0.wps_employer_iban', $companyA->wps_employer_iban));
});

test('companies index search cannot surface a company outside the active tenant', function () {
    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    grantCompanyPermissions($user, $companyA, ['companies.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->get('/organization/companies?search='.urlencode($companyB->name))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('companies', 0));
});

test('users without companies.view cannot open the companies index', function () {
    ['user' => $user, 'companyA' => $companyA] = makeCompanyAuthorizationPair();
    grantCompanyPermissions($user, $companyA, ['employees.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->get('/organization/companies')
        ->assertForbidden();
});

test('company show is limited to the active company', function () {
    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    grantCompanyPermissions($user, $companyA, ['companies.view']);
    grantCompanyPermissions($user, $companyB, ['companies.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->get("/organization/companies/{$companyA->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('organization/company')
            ->where('company.id', $companyA->id)
            ->where('company.wps_employer_iban', $companyA->wps_employer_iban)
            ->where('company.tax_id', $companyA->tax_id));

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->get("/organization/companies/{$companyB->id}")
        ->assertNotFound();
});

test('company show does not leak another tenant when the user is not a member', function () {
    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    grantCompanyPermissions($user, $companyA, ['companies.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->get("/organization/companies/{$companyB->id}")
        ->assertNotFound()
        ->assertDontSee($companyB->wps_employer_iban, false)
        ->assertDontSee($companyB->tax_id, false);
});

test('users without companies.view cannot show the active company', function () {
    ['user' => $user, 'companyA' => $companyA] = makeCompanyAuthorizationPair();
    grantCompanyPermissions($user, $companyA, ['employees.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->get("/organization/companies/{$companyA->id}")
        ->assertForbidden();
});

test('company update is allowed for the active company and rejected for another tenant', function () {
    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    grantCompanyPermissions($user, $companyA, ['companies.update']);
    grantCompanyPermissions($user, $companyB, ['companies.update']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->put("/organization/companies/{$companyA->id}", [
            'name' => 'Alpha Updated',
            'slug' => $companyA->slug,
            'company_id' => $companyB->id,
        ])
        ->assertRedirect('/organization/companies');

    expect($companyA->fresh()->name)->toBe('Alpha Updated')
        ->and($companyB->fresh()->name)->toStartWith('Beta Registry');

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->put("/organization/companies/{$companyB->id}", [
            'name' => 'Hijacked Beta',
            'slug' => $companyB->slug,
        ])
        ->assertNotFound();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->put("/organization/companies/{$companyB->id}", [
            'name' => '',
        ])
        ->assertNotFound();

    expect($companyB->fresh()->name)->toStartWith('Beta Registry');
});

test('a dual-member updater must switch before mutating the other company', function () {
    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    grantCompanyPermissions($user, $companyA, ['companies.update']);
    grantCompanyPermissions($user, $companyB, ['companies.update']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyB->id])
        ->put("/organization/companies/{$companyB->id}", [
            'name' => 'Beta Updated After Switch',
            'slug' => $companyB->slug,
        ])
        ->assertRedirect('/organization/companies');

    expect($companyB->fresh()->name)->toBe('Beta Updated After Switch');
});

test('company status cannot be changed on another tenant', function () {
    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    grantCompanyPermissions($user, $companyA, ['companies.update']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->put("/organization/companies/{$companyA->id}/status", ['status' => 'inactive'])
        ->assertRedirect('/organization/companies');

    expect($companyA->fresh()->status)->toBe('inactive');

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->put("/organization/companies/{$companyB->id}/status", ['status' => 'inactive'])
        ->assertNotFound();

    expect($companyB->fresh()->status)->toBe('active');
});

test('company destroy is limited to the active company', function () {
    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    grantCompanyPermissions($user, $companyA, ['companies.delete']);
    grantCompanyPermissions($user, $companyB, ['companies.delete']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->delete("/organization/companies/{$companyB->id}")
        ->assertNotFound();

    expect(Company::query()->whereKey($companyB->id)->exists())->toBeTrue();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->delete("/organization/companies/{$companyA->id}")
        ->assertRedirect('/organization/companies');

    $this->assertSoftDeleted('companies', ['id' => $companyA->id]);
});

test('soft-deleted companies are not reachable by id', function () {
    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    grantCompanyPermissions($user, $companyA, ['companies.view']);
    grantCompanyPermissions($user, $companyB, ['companies.view']);
    $companyB->delete();

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->get("/organization/companies/{$companyB->id}")
        ->assertNotFound();
});

test('company export contains only the active company', function () {
    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    grantCompanyPermissions($user, $companyA, ['companies.export']);

    $csv = $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->get('/organization/companies/export?format=csv')
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain($companyA->name)
        ->and($csv)->not->toContain($companyB->name)
        ->and($csv)->not->toContain($companyB->tax_id);

    $filtered = $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->get('/organization/companies/export?format=csv&search='.urlencode($companyB->name))
        ->assertOk()
        ->streamedContent();

    expect($filtered)->not->toContain($companyB->name)
        ->and($filtered)->not->toContain($companyA->name);
});

test('company switch rejects inaccessible companies', function () {
    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    grantCompanyPermissions($user, $companyA, ['companies.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->post('/organization/companies/switch', ['company_id' => $companyB->id])
        ->assertForbidden();

    expect(session('current_company_id'))->toBe($companyA->id)
        ->and(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($companyA->id);
});

test('dual-member can switch to the other accessible company', function () {
    ['user' => $user, 'companyA' => $companyA, 'companyB' => $companyB] = makeCompanyAuthorizationPair();
    grantCompanyPermissions($user, $companyA, ['companies.view']);
    grantCompanyPermissions($user, $companyB, ['companies.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->post('/organization/companies/switch', ['company_id' => $companyB->id])
        ->assertRedirect();

    expect(session('current_company_id'))->toBe($companyB->id)
        ->and(app(PermissionRegistrar::class)->getPermissionsTeamId())->toBe($companyB->id);
});

test('platform access without membership cannot switch into a tenant', function () {
    ['companyA' => $companyA] = makeCompanyAuthorizationPair();
    $platformUser = User::factory()->create(['company_id' => null]);
    $platformUser->forceFill(['platform_access' => PlatformAccess::Manage])->save();

    $this->actingAs($platformUser)
        ->withSession([])
        ->post('/organization/companies/switch', ['company_id' => $companyA->id])
        ->assertForbidden();

    expect(session('current_company_id'))->toBeNull();
});

test('platform access without membership does not list the companies registry', function () {
    ['companyA' => $companyA] = makeCompanyAuthorizationPair();
    $platformUser = User::factory()->create(['company_id' => null]);
    $platformUser->forceFill(['platform_access' => PlatformAccess::Manage])->save();

    $this->actingAs($platformUser)
        ->get('/organization/companies')
        ->assertForbidden();

    $this->actingAs($platformUser)
        ->get("/organization/companies/{$companyA->id}")
        ->assertForbidden();
});
