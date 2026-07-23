<?php

use App\Models\AppSetting;
use App\Models\Company;
use App\Models\CompanyDocumentSetting;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Employee;
use App\Models\User;
use App\Support\Employees\Services\SalaryCertificateData;
use App\Support\Employees\Services\SalaryDeclarationData;
use App\Support\Settings\CompanyDocumentType;
use App\Support\Settings\SettingKey;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('user with companies.view can view company page and document settings', function () {
    $user = User::factory()->create();
    $company = createCompanyForSettings('Show Co', 'show-co', 'AED', 'Asia/Dubai');
    grantCompanyPermissions($user, $company, ['companies.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.companies.show', $company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/company')
            ->has('document_settings')
            ->where('document_settings.document_type', CompanyDocumentType::SalaryCertificate)
            ->where('document_settings.can_update', false),
        );
});

test('user without companies.view cannot view the company page', function () {
    $user = User::factory()->create();
    $company = createCompanyForSettings('Denied Co', 'denied-co', 'AED', 'Asia/Dubai');
    grantCompanyPermissions($user, $company, ['companies.update']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.companies.show', $company))
        ->assertForbidden();
});

test('authorized user can update company document settings for active company', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $company = createCompanyForSettings('Doc Co A', 'doc-co-a', 'AED', 'Asia/Dubai');
    grantCompanyPermissions($user, $company, [
        'companies.view',
        'companies.update',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put(route('organization.companies.document-settings.update', $company), [
            'document_type' => CompanyDocumentType::SalaryCertificate,
            'signatory_name' => 'Jane HR',
            'signatory_title' => 'HR Manager',
            'footer_text' => 'Authorized',
            'signature' => UploadedFile::fake()->image('sig.png', 200, 80),
            'stamp' => UploadedFile::fake()->image('stamp.png', 200, 80),
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $setting = CompanyDocumentSetting::query()
        ->where('company_id', $company->id)
        ->where('document_type', CompanyDocumentType::SalaryCertificate)
        ->first();

    expect($setting)->not->toBeNull()
        ->and($setting->signatory_name)->toBe('Jane HR')
        ->and($setting->signatory_title)->toBe('HR Manager');

    Storage::disk('public')->assertExists((string) $setting->signature_path);
    Storage::disk('public')->assertExists((string) $setting->stamp_path);

    expect(Activity::query()->where('description', 'updated company document settings')->exists())->toBeTrue();
});

test('user without companies.update receives 403 when updating document settings', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $company = createCompanyForSettings('View Only Co', 'view-only-co', 'AED', 'Asia/Dubai');
    grantCompanyPermissions($user, $company, ['companies.view']);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put(route('organization.companies.document-settings.update', $company), [
            'document_type' => CompanyDocumentType::SalaryCertificate,
            'signatory_name' => 'Should Fail',
        ])
        ->assertForbidden();
});

test('user with companies.update can remove a signature or stamp', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $company = createCompanyForSettings('Remove Co', 'remove-co', 'AED', 'Asia/Dubai');
    grantCompanyPermissions($user, $company, [
        'companies.view',
        'companies.update',
    ]);

    $signature = UploadedFile::fake()->image('sig.png')->store(
        'company-document-settings/'.$company->id,
        'public',
    );
    $stamp = UploadedFile::fake()->image('stamp.png')->store(
        'company-document-settings/'.$company->id,
        'public',
    );

    CompanyDocumentSetting::query()->create([
        'company_id' => $company->id,
        'document_type' => CompanyDocumentType::SalaryCertificate,
        'signature_path' => $signature,
        'stamp_path' => $stamp,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->delete(route('organization.companies.document-settings.asset.destroy', [
            'company' => $company,
            'asset' => 'signature',
        ]), [
            'document_type' => CompanyDocumentType::SalaryCertificate,
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $setting = CompanyDocumentSetting::query()
        ->where('company_id', $company->id)
        ->where('document_type', CompanyDocumentType::SalaryCertificate)
        ->first();

    expect($setting->signature_path)->toBeNull()
        ->and($setting->stamp_path)->toBe($stamp);

    Storage::disk('public')->assertMissing($signature);
    Storage::disk('public')->assertExists($stamp);
});

test('user cannot update document settings for another company', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $companyA = createCompanyForSettings('Doc Co A', 'doc-co-a-x', 'AED', 'Asia/Dubai');
    $companyB = createCompanyForSettings('Doc Co B', 'doc-co-b-x', 'USD', 'UTC');

    grantCompanyPermissions($user, $companyA, [
        'companies.update',
    ]);
    grantCompanyPermissions($user, $companyB, [
        'companies.update',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $companyA->id])
        ->put(route('organization.companies.document-settings.update', $companyB), [
            'document_type' => CompanyDocumentType::SalaryCertificate,
            'signatory_name' => 'Wrong Co',
        ])
        ->assertForbidden();
});

test('salary certificate uses employee company identity and document assets', function () {
    Storage::fake('public');

    $companyA = createCompanyForSettings('Alpha Marine', 'alpha-marine', 'AED', 'Asia/Dubai');
    $companyB = createCompanyForSettings('Beta Marine', 'beta-marine', 'USD', 'America/New_York');

    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::CompanyName],
        ['value' => 'GLOBAL OVERRIDE NAME', 'type' => 'string'],
    );

    $sigA = UploadedFile::fake()->image('a-sig.png')->store('company-document-settings/'.$companyA->id, 'public');
    $sigB = UploadedFile::fake()->image('b-sig.png')->store('company-document-settings/'.$companyB->id, 'public');

    CompanyDocumentSetting::query()->create([
        'company_id' => $companyA->id,
        'document_type' => CompanyDocumentType::SalaryCertificate,
        'signatory_name' => 'Alpha Signer',
        'signature_path' => $sigA,
    ]);

    CompanyDocumentSetting::query()->create([
        'company_id' => $companyB->id,
        'document_type' => CompanyDocumentType::SalaryCertificate,
        'signatory_name' => 'Beta Signer',
        'signature_path' => $sigB,
    ]);

    $employeeA = Employee::factory()->forCompany($companyA)->create(['name' => 'Alice']);
    $employeeB = Employee::factory()->forCompany($companyB)->create(['name' => 'Bob']);

    $dataA = SalaryCertificateData::for($employeeA, (int) $companyA->id);
    $dataB = SalaryCertificateData::for($employeeB, (int) $companyB->id);

    expect($dataA['company_name'])->toBe('Alpha Marine')
        ->and($dataA['currency_code'])->toBe('AED')
        ->and($dataA['signatory_name'])->toBe('Alpha Signer')
        ->and($dataA['company_name'])->not->toBe('GLOBAL OVERRIDE NAME')
        ->and($dataB['company_name'])->toBe('Beta Marine')
        ->and($dataB['currency_code'])->toBe('USD')
        ->and($dataB['signatory_name'])->toBe('Beta Signer');
});

test('salary certificate falls back to legacy global signature when company setting missing', function () {
    Storage::fake('public');

    $company = createCompanyForSettings('Legacy Co', 'legacy-co', 'AED', 'Asia/Dubai');
    $legacyPath = UploadedFile::fake()->image('legacy-sig.png')->store('settings', 'public');

    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::SalaryCertificateSignature],
        ['value' => $legacyPath, 'type' => 'file'],
    );

    $employee = Employee::factory()->forCompany($company)->create();
    $data = SalaryCertificateData::for($employee, (int) $company->id);

    expect($data['signature_image_url'])->not->toBeNull()
        ->and(str_starts_with((string) $data['signature_image_url'], 'data:image/'))->toBeTrue();
});

test('salary declaration uses employee company name not global setting', function () {
    $company = createCompanyForSettings('Declaration Co', 'declaration-co', 'AED', 'Asia/Dubai');

    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::CompanyName],
        ['value' => 'GLOBAL NAME', 'type' => 'string'],
    );

    $employee = Employee::factory()->forCompany($company)->create();
    $data = SalaryDeclarationData::for($employee, (int) $company->id);

    expect($data['company_name'])->toBe('Declaration Co');
});

test('failed signature replacement preserves existing company asset', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $company = createCompanyForSettings('Safe Co', 'safe-co', 'AED', 'Asia/Dubai');
    grantCompanyPermissions($user, $company, ['companies.update']);

    $existing = UploadedFile::fake()->image('existing.png')->store(
        'company-document-settings/'.$company->id,
        'public',
    );

    CompanyDocumentSetting::query()->create([
        'company_id' => $company->id,
        'document_type' => CompanyDocumentType::SalaryCertificate,
        'signature_path' => $existing,
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->put(route('organization.companies.document-settings.update', $company), [
            'document_type' => CompanyDocumentType::SalaryCertificate,
            'signature' => UploadedFile::fake()->create('bad.txt', 10, 'text/plain'),
        ])
        ->assertSessionHasErrors('signature');

    $setting = CompanyDocumentSetting::query()
        ->where('company_id', $company->id)
        ->first();

    expect($setting->signature_path)->toBe($existing);
    Storage::disk('public')->assertExists($existing);
});

test('company show with companies.update exposes can_update on document settings', function () {
    $user = User::factory()->create();
    $company = createCompanyForSettings('Edit Co', 'edit-co', 'AED', 'Asia/Dubai');
    grantCompanyPermissions($user, $company, [
        'companies.view',
        'companies.update',
    ]);

    $this->actingAs($user)
        ->withSession(['current_company_id' => $company->id])
        ->get(route('organization.companies.show', $company))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('organization/company')
            ->has('document_settings')
            ->where('document_settings.can_update', true),
        );
});

function createCompanyForSettings(string $name, string $slug, string $currencyCode, string $timezone): Company
{
    $country = Country::query()->firstOrCreate(
        ['code' => strtoupper(substr($slug, 0, 3))],
        [
            'name' => $name.' Country',
            'dial_code' => '+971',
            'is_active' => true,
        ],
    );

    $currency = Currency::query()->firstOrCreate(
        ['code' => $currencyCode],
        [
            'name' => $currencyCode.' Currency',
            'symbol' => $currencyCode,
            'is_active' => true,
        ],
    );

    return Company::query()->create([
        'name' => $name,
        'slug' => $slug,
        'working_days' => [1, 2, 3, 4, 5],
        'country_id' => $country->id,
        'currency_id' => $currency->id,
        'timezone' => $timezone,
        'payroll_cycle' => 'monthly',
        'email' => strtolower($slug).'@example.test',
        'status' => 'active',
    ]);
}
