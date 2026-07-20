<?php

use App\Models\AppSetting;
use App\Models\CompanyDocumentSetting;
use App\Models\Employee;
use App\Support\Employees\Services\SalaryCertificateData;
use App\Support\Settings\CompanyDocumentType;
use App\Support\Settings\SettingKey;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('uses company certificate assets only while their effective dates are active', function () {
    Storage::fake('public');
    Carbon::setTestNow(Carbon::parse('2026-07-20 10:00:00', 'Asia/Dubai'));

    ['company' => $company] = makeDocumentFixtures();
    $company->update(['timezone' => 'Asia/Dubai']);
    $employee = Employee::factory()->forCompany($company)->create();

    $legacyPath = UploadedFile::fake()->image('legacy.png')->store('settings', 'public');
    $futurePath = UploadedFile::fake()->image('future.png')->store('company-document-settings/'.$company->id, 'public');

    AppSetting::query()->updateOrCreate(
        ['key' => SettingKey::SalaryCertificateSignature],
        ['value' => $legacyPath, 'type' => 'file'],
    );

    $setting = CompanyDocumentSetting::query()->create([
        'company_id' => $company->id,
        'document_type' => CompanyDocumentType::SalaryCertificate,
        'signatory_name' => 'Future Signer',
        'signature_path' => $futurePath,
        'effective_from' => '2026-08-01',
    ]);

    $futureData = SalaryCertificateData::for($employee, $company->id);

    expect($futureData['signatory_name'])->toBeNull()
        ->and($futureData['signature_image_url'])->toContain(base64_encode(Storage::disk('public')->get($legacyPath)));

    $setting->update([
        'effective_from' => '2026-07-01',
        'effective_to' => '2026-07-31',
    ]);

    $activeData = SalaryCertificateData::for($employee->fresh(), $company->id);

    expect($activeData['signatory_name'])->toBe('Future Signer')
        ->and($activeData['signature_image_url'])->toContain(base64_encode(Storage::disk('public')->get($futurePath)));

    Carbon::setTestNow();
});
