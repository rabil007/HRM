<?php

use App\Models\CompanyDocumentSetting;
use App\Support\Settings\CompanyDocumentType;
use App\Support\Settings\StoresCompanyDocumentSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('replaces a company logo only after the new file is stored', function () {
    Storage::fake('public');

    ['company' => $company] = makeDocumentFixtures();

    $oldLogo = UploadedFile::fake()->image('old-logo.png')->store('company-logos', 'public');
    $company->forceFill(['logo' => $oldLogo])->saveQuietly();

    $newLogo = UploadedFile::fake()->image('new-logo.png')->store('company-logos', 'public');
    $company->update(['logo' => $newLogo]);

    Storage::disk('public')->delete($oldLogo);

    expect($company->fresh()->logo)->toBe($newLogo);
    Storage::disk('public')->assertExists($newLogo);
    Storage::disk('public')->assertMissing($oldLogo);
});

it('replaces company document assets and removes old files after persistence succeeds', function () {
    Storage::fake('public');

    ['company' => $company] = makeDocumentFixtures();
    $oldSignature = UploadedFile::fake()->image('old-signature.png')->store(
        'company-document-settings/'.$company->id,
        'public',
    );

    $setting = CompanyDocumentSetting::query()->create([
        'company_id' => $company->id,
        'document_type' => CompanyDocumentType::SalaryCertificate,
        'signature_path' => $oldSignature,
    ]);

    $saved = app(StoresCompanyDocumentSetting::class)->update(
        $company->id,
        CompanyDocumentType::SalaryCertificate,
        [],
        ['signature' => UploadedFile::fake()->image('new-signature.png')],
        null,
    );

    expect($saved->signature_path)->not->toBe($oldSignature)
        ->and($setting->fresh()->signature_path)->toBe($saved->signature_path);

    Storage::disk('public')->assertExists((string) $saved->signature_path);
    Storage::disk('public')->assertMissing($oldSignature);
});
