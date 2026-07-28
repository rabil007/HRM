<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Support\Companies\ActivateCompanySession;
use App\Support\EmployeeDocuments\DocumentExpiryStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

class OpenDocumentComplianceNotificationController extends Controller
{
    public function __invoke(
        Request $request,
        Company $company,
        ActivateCompanySession $activateCompany,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $activateCompany->handle($user, (int) $company->id, $request);

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($company->id);
            abort_unless($user->can('documents.view'), 403);
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }

        return redirect()->route('organization.documents', [
            'expiry' => DocumentExpiryStatus::Expiring30->value,
        ]);
    }
}
