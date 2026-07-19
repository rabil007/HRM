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
        abort_unless(Company::query()->whereKey($companyId)->exists(), 404);

        $setting = CompanyDocumentSetting::query()
            ->where('company_id', $companyId)
            ->where('document_type', $documentType)
            ->first();

        $signature = $this->resolveAsset(
            $setting?->signature_path,
            SettingKey::SalaryCertificateSignature,
            'images/salary-certificate/signature.png',
        );

        $stamp = $this->resolveAsset(
            $setting?->stamp_path,
            SettingKey::SalaryCertificateStamp,
            'images/salary-certificate/stamp.png',
        );

        return [
            'setting' => $setting,
            'signatory_name' => $setting?->signatory_name,
            'signatory_title' => $setting?->signatory_title,
            'signature_path' => $signature['path'],
            'stamp_path' => $stamp['path'],
            'footer_text' => $setting?->footer_text,
            'signature_source' => $signature['source'],
            'stamp_source' => $stamp['source'],
        ];
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
