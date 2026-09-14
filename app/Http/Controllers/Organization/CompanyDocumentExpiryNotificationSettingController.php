<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CompanyDocument\UpdateCompanyDocumentExpiryNotificationSettingRequest;
use App\Models\Company;
use App\Support\CompanyDocuments\CompanyDocumentAccess;
use App\Support\CompanyDocuments\CompanyDocumentExpiryNotificationPresenter;
use App\Support\CompanyDocuments\UpdateCompanyDocumentExpiryNotificationSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CompanyDocumentExpiryNotificationSettingController extends Controller
{
    public function show(
        Request $request,
        Company $company,
        CompanyDocumentAccess $access,
        CompanyDocumentExpiryNotificationPresenter $presenter,
    ): JsonResponse {
        $access->authorize($request->user(), $company, CompanyDocumentAccess::Abilities['manage_notifications']);

        return response()->json($presenter->presentForCompany($company->id));
    }

    public function update(
        UpdateCompanyDocumentExpiryNotificationSettingRequest $request,
        Company $company,
        CompanyDocumentAccess $access,
        UpdateCompanyDocumentExpiryNotificationSettings $updateSettings,
    ): RedirectResponse {
        $access->authorize($request->user(), $company, CompanyDocumentAccess::Abilities['manage_notifications']);

        $updateSettings->handle(
            $company,
            $request->boolean('enabled'),
            $request->toUserIds(),
            $request->ccUserIds(),
            $request->user(),
        );

        return back()->with('success', 'Company document expiry notification settings saved.');
    }
}
