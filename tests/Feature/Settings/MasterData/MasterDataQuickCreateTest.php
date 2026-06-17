<?php

use App\Models\Bank;
use App\Models\Company;
use App\Models\CompanyVisaType;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Department;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\Vessel;
use App\Models\VesselType;
use App\Models\VisaType;

/**
 * @return array{user: User, company: Company}
 */
function quickCreateMasterDataUser(): array
{
    $user = User::factory()->create();

    $country = Country::query()->create([
        'code' => 'TST',
        'name' => 'Testland',
        'dial_code' => '+999',
        'is_active' => true,
    ]);

    $currency = Currency::query()->create([
        'code' => 'TST',
        'name' => 'Test Currency',
        'symbol' => 'T$',
        'is_active' => true,
    ]);

    $company = Company::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => 'Asia/Dubai',
        'payroll_cycle' => 'monthly',
        'status' => 'active',
    ]);

    return ['user' => $user, 'company' => $company];
}

test('json quick-create returns id and label for banks', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.banks.create',
    ]);

    $response = $this->postJson('/settings/master-data/banks', [
        'name' => 'SIB',
        'is_active' => true,
    ]);

    $response
        ->assertSuccessful()
        ->assertJson([
            'label' => 'SIB',
            'name' => 'SIB',
        ]);

    $id = $response->json('id');
    expect($id)->not->toBeNull();
    expect(Bank::query()->whereKey($id)->value('name'))->toBe('SIB');
});

test('json quick-create returns id and label for visa types', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.visa-types.create',
    ]);

    $this->postJson('/settings/master-data/visa-types', [
        'name' => 'Visit Visa',
        'is_active' => true,
    ])
        ->assertSuccessful()
        ->assertJson([
            'label' => 'Visit Visa',
            'name' => 'Visit Visa',
        ]);
});

test('json quick-create rejects duplicate visa type names with 422', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.visa-types.create',
    ]);

    VisaType::query()->create([
        'name' => 'Employment Visa',
        'is_active' => true,
    ]);

    $this->postJson('/settings/master-data/visa-types', [
        'name' => 'Employment Visa',
        'is_active' => true,
    ])->assertUnprocessable();
});

test('json quick-create returns existing visa type when name matches case-insensitively', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.visa-types.create',
    ]);

    $existing = VisaType::query()->create([
        'name' => 'Golden Visa',
        'is_active' => true,
    ]);

    $this->postJson('/settings/master-data/visa-types', [
        'name' => 'golden visa',
        'is_active' => true,
    ])
        ->assertSuccessful()
        ->assertJson([
            'id' => $existing->id,
            'label' => 'Golden Visa',
        ]);

    expect(VisaType::query()->count())->toBe(1);
});

test('json quick-create returns id and label for company visa types', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.company-visa-types.create',
    ]);

    $this->postJson('/settings/master-data/company-visa-types', [
        'name' => 'Company Visit Visa',
        'is_active' => true,
    ])
        ->assertSuccessful()
        ->assertJson([
            'label' => 'Company Visit Visa',
            'name' => 'Company Visit Visa',
        ]);
});

test('json quick-create rejects duplicate company visa type names with 422', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.company-visa-types.create',
    ]);

    CompanyVisaType::query()->create([
        'name' => 'Company Employment Visa',
        'is_active' => true,
    ]);

    $this->postJson('/settings/master-data/company-visa-types', [
        'name' => 'Company Employment Visa',
        'is_active' => true,
    ])->assertUnprocessable();
});

test('json quick-create returns existing company visa type when name matches case-insensitively', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.company-visa-types.create',
    ]);

    $existing = CompanyVisaType::query()->create([
        'name' => 'Company Golden Visa',
        'is_active' => true,
    ]);

    $this->postJson('/settings/master-data/company-visa-types', [
        'name' => 'company golden visa',
        'is_active' => true,
    ])
        ->assertSuccessful()
        ->assertJson([
            'id' => $existing->id,
            'label' => 'Company Golden Visa',
        ]);

    expect(CompanyVisaType::query()->count())->toBe(1);
});

test('json quick-create returns id and title for document types', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.document-types.create',
    ]);

    $this->postJson('/settings/master-data/document-types', [
        'title' => 'Seaman Book',
        'is_active' => true,
    ])
        ->assertSuccessful()
        ->assertJson([
            'label' => 'Seaman Book',
            'title' => 'Seaman Book',
        ]);

    expect(DocumentType::query()->where('title', 'Seaman Book')->exists())->toBeTrue();
});

test('json quick-create returns id and label for vessels with vessel type context', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    $vesselType = VesselType::query()->create([
        'name' => 'AHTS',
        'is_active' => true,
    ]);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.vessels.create',
    ]);

    $this->postJson('/settings/master-data/vessels', [
        'name' => 'MV Horizon',
        'vessel_type_id' => $vesselType->id,
        'is_active' => true,
    ])
        ->assertSuccessful()
        ->assertJson([
            'label' => 'MV Horizon',
            'name' => 'MV Horizon',
        ]);

    expect(Vessel::query()
        ->where('name', 'MV Horizon')
        ->where('vessel_type_id', $vesselType->id)
        ->exists())->toBeTrue();
});

test('json quick-create returns id and label for departments', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'departments.create',
    ]);

    $this->postJson('/organization/departments', [
        'name' => 'Engineering',
        'status' => 'active',
    ])
        ->assertSuccessful()
        ->assertJson([
            'label' => 'Engineering',
            'name' => 'Engineering',
        ]);

    expect(Department::query()
        ->where('company_id', $company->id)
        ->where('name', 'Engineering')
        ->exists())->toBeTrue();
});

test('non-json bank store still redirects to index', function () {
    ['user' => $user, 'company' => $company] = quickCreateMasterDataUser();
    $this->actingAs($user);

    grantCompanyPermissions($user, $company, [
        'settings.master-data.banks.create',
    ]);

    $this->post('/settings/master-data/banks', [
        'name' => 'Redirect Bank',
        'is_active' => true,
    ])->assertRedirect(route('settings.master-data.banks.index'));
});
