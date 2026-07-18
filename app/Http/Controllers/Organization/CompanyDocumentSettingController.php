<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Company\UpdateCompanyDocumentSettingRequest;
use App\Models\Company;
use App\Models\CompanyDocumentSetting;
use App\Models\User;
use App\Support\Settings\CompanyDocumentSettingResolver;
use App\Support\Settings\CompanyDocumentType;
use App\Support\Settings\StoresCompanyDocumentSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class CompanyDocumentSettingController extends Controller
{
    public function update(
        UpdateCompanyDocumentSettingRequest $request,
        Company $company,
        StoresCompanyDocumentSetting $store,
    ): RedirectResponse {
        $this->assertActiveCompany($request, $company);

        $setting = $store->update(
            (int) $company->id,
            $request->documentType(),
            $request->settingPayload(),
            $request->uploadFiles(),
            $request->user()?->id,
        );

        activity()
            ->causedBy($request->user())
            ->performedOn($setting)
            ->withProperties([
                'company_id' => $company->id,
                'document_type' => $setting->document_type,
                'signatory_name' => $setting->signatory_name,
                'signatory_title' => $setting->signatory_title,
                'has_signature' => filled($setting->signature_path),
                'has_stamp' => filled($setting->stamp_path),
                'effective_from' => $setting->effective_from?->toDateString(),
                'effective_to' => $setting->effective_to?->toDateString(),
            ])
            ->log('updated company document settings');

        return back()->with('success', 'Document settings saved.');
    }

    public function destroyAsset(
        Request $request,
        Company $company,
        string $asset,
        StoresCompanyDocumentSetting $store,
    ): RedirectResponse {
        if (! $request->user()?->can('company.document-settings.update')) {
            abort(403);
        }

        $this->assertActiveCompany($request, $company);

        if (! in_array($asset, ['signature', 'stamp'], true)) {
            abort(404);
        }

        $documentType = (string) $request->input('document_type', CompanyDocumentType::SalaryCertificate);

        abort_unless(CompanyDocumentType::isValid($documentType), 404);

        $existing = CompanyDocumentSetting::query()
            ->where('company_id', $company->id)
            ->where('document_type', $documentType)
            ->first();

        $setting = $store->update(
            (int) $company->id,
            $documentType,
            [
                'signatory_name' => $existing?->signatory_name,
                'signatory_title' => $existing?->signatory_title,
                'footer_text' => $existing?->footer_text,
                'effective_from' => $existing?->effective_from?->toDateString(),
                'effective_to' => $existing?->effective_to?->toDateString(),
                'remove_signature' => $asset === 'signature',
                'remove_stamp' => $asset === 'stamp',
            ],
            [],
            $request->user()?->id,
        );

        activity()
            ->causedBy($request->user())
            ->performedOn($setting)
            ->withProperties([
                'company_id' => $company->id,
                'document_type' => $documentType,
                'removed_asset' => $asset,
            ])
            ->log('removed company document setting asset');

        return back()->with('success', 'Asset removed.');
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function salaryCertificateProps(int $companyId, ?User $user): ?array
    {
        if (! $user?->can('company.document-settings.view')) {
            return null;
        }

        $resolved = app(CompanyDocumentSettingResolver::class)
            ->resolve($companyId, CompanyDocumentType::SalaryCertificate);

        $setting = $resolved['setting'];

        return [
            'document_type' => CompanyDocumentType::SalaryCertificate,
            'signatory_name' => $resolved['signatory_name'],
            'signatory_title' => $resolved['signatory_title'],
            'footer_text' => $resolved['footer_text'],
            'signature_url' => $setting?->signatureUrl(),
            'stamp_url' => $setting?->stampUrl(),
            'has_signature' => filled($resolved['signature_path']) && $resolved['signature_source'] === 'company',
            'has_stamp' => filled($resolved['stamp_path']) && $resolved['stamp_source'] === 'company',
            'using_legacy_signature' => $resolved['signature_source'] === 'legacy',
            'using_legacy_stamp' => $resolved['stamp_source'] === 'legacy',
            'effective_from' => $setting?->effective_from?->toDateString(),
            'effective_to' => $setting?->effective_to?->toDateString(),
            'can_update' => $user->can('company.document-settings.update'),
        ];
    }

    private function assertActiveCompany(Request $request, Company $company): void
    {
        $activeCompanyId = (int) $request->attributes->get('current_company_id');

        if ($activeCompanyId < 1 || (int) $company->id !== $activeCompanyId) {
            throw new AccessDeniedHttpException('You can only manage document settings for the active company.');
        }
    }
}
