<?php

namespace App\Support\Settings;

use App\Models\Company;
use App\Models\CompanyDocumentSetting;
use App\Services\Settings\SettingService;
use Illuminate\Support\Facades\Storage;

final class CompanyDocumentSettingResolver
{
    public function __construct(
        private SettingService $settings,
    ) {}

    /**
     * @return array{
     *     setting: CompanyDocumentSetting|null,
     *     is_effective: bool,
     *     signatory_name: string|null,
     *     signatory_title: string|null,
     *     signature_path: string|null,
     *     stamp_path: string|null,
     *     footer_text: string|null,
     *     signature_source: 'company'|'legacy'|'fallback'|'none',
     *     stamp_source: 'company'|'legacy'|'fallback'|'none',
     * }
     */
    public function resolve(int $companyId, string $documentType = CompanyDocumentType::SalaryCertificate): array
    {
        abort_unless(CompanyDocumentType::isValid($documentType), 404);

        $company = Company::query()->select(['id', 'timezone'])->find($companyId);
        abort_unless($company !== null, 404);

        $setting = CompanyDocumentSetting::query()
            ->where('company_id', $companyId)
            ->where('document_type', $documentType)
            ->first();

        $isEffective = $this->isEffective($setting, CompanyTimezone::forCompany($company));
        $effectiveSetting = $isEffective ? $setting : null;

        $signature = $this->resolveAsset(
            $effectiveSetting?->signature_path,
            SettingKey::SalaryCertificateSignature,
            'images/salary-certificate/signature.png',
        );

        $stamp = $this->resolveAsset(
            $effectiveSetting?->stamp_path,
            SettingKey::SalaryCertificateStamp,
            'images/salary-certificate/stamp.png',
        );

        return [
            'setting' => $setting,
            'is_effective' => $isEffective,
            'signatory_name' => $effectiveSetting?->signatory_name,
            'signatory_title' => $effectiveSetting?->signatory_title,
            'signature_path' => $signature['path'],
            'stamp_path' => $stamp['path'],
            'footer_text' => $effectiveSetting?->footer_text,
            'signature_source' => $signature['source'],
            'stamp_source' => $stamp['source'],
        ];
    }

    private function isEffective(?CompanyDocumentSetting $setting, string $timezone): bool
    {
        if ($setting === null) {
            return false;
        }

        $today = now($timezone)->toDateString();

        if ($setting->effective_from !== null && $setting->effective_from->toDateString() > $today) {
            return false;
        }

        if ($setting->effective_to !== null && $setting->effective_to->toDateString() < $today) {
            return false;
        }

        return true;
    }

    /**
     * @return array{path: string|null, source: 'company'|'legacy'|'fallback'|'none'}
     */
    private function resolveAsset(?string $companyPath, string $legacyKey, string $publicRelativePath): array
    {
        if (filled($companyPath) && Storage::disk('public')->exists((string) $companyPath)) {
            return [
                'path' => (string) $companyPath,
                'source' => 'company',
            ];
        }

        $legacyPath = $this->settings->get($legacyKey);

        if (filled($legacyPath) && Storage::disk('public')->exists((string) $legacyPath)) {
            return [
                'path' => (string) $legacyPath,
                'source' => 'legacy',
            ];
        }

        $fallback = public_path($publicRelativePath);

        if (is_file($fallback)) {
            return [
                'path' => $fallback,
                'source' => 'fallback',
            ];
        }

        return [
            'path' => null,
            'source' => 'none',
        ];
    }
}
