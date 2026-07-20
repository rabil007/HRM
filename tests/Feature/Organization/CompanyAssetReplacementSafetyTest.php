<?php

use App\Models\Company;
use App\Models\CompanyDocumentSetting;
use App\Models\User;
use App\Support\Settings\CompanyDocumentType;
use App\Support\Settings\StoresCompanyDocumentSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('preserves the previous company logo when the database update fails', function () {
    Storage::fake('public');

    ['company' => $company] = makeDocumentFixtures();
    $user = User::factory()->create();
    grantCompanyPermissions($user, $company, ['companies.update']);

    $oldLogo = UploadedFile::fake()->image('old-logo.png')->store('company-logos', 'public');
    $company->forceFill(['logo' => $oldLogo])->saveQuietly();

    Company::updating(function (): void {
        throw new RuntimeException('Simulated update failure.');
    });

    try {
        $this->actingAs($user)
            ->withSession(['current_company_id' => $company->id])
            ->put(route('organization.companies.update', $company), [
                'name' => $company->name,
                'logo' => UploadedFile::fake()->image('new-logo.png'),
            ]);
    } catch (RuntimeException) {
        // Expected test failure path.
    } finally {
        Company::flushEventListeners();
    }

    expect($company->fresh()->logo)->toBe($oldLogo);
    Storage::disk('public')->assertExists($oldLogo);
    expect(Storage::disk('public')->allFiles('company-logos'))->toHaveCount(1);
});

it('deletes newly stored document assets and preserves old assets when persistence fails', function () {
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

    CompanyDocumentSetting::saving(function (): void {
        throw new RuntimeException('Simulated persistence failure.');
    });

    try {
        app(StoresCompanyDocumentSetting::class)->update(
            $company->id,
            CompanyDocumentType::SalaryCertificate,
            [],
            ['signature' => UploadedFile::fake()->image('new-signature.png')],
            null,
        );
    } catch (RuntimeException) {
        // Expected test failure path.
    } finally {
        CompanyDocumentSetting::flushEventListeners();
    }

    expect($setting->fresh()->signature_path)->toBe($oldSignature);
    Storage::disk('public')->assertExists($oldSignature);
    expect(Storage::disk('public')->allFiles('company-document-settings/'.$company->id))->toHaveCount(1);
});
