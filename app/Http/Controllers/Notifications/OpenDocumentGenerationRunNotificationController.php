<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\DocumentGenerationRun;
use App\Support\Companies\ActivateCompanySession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Spatie\Permission\PermissionRegistrar;

class OpenDocumentGenerationRunNotificationController extends Controller
{
    public function __invoke(
        Request $request,
        DocumentGenerationRun $run,
        ActivateCompanySession $activateCompany,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless((int) $run->triggered_by === (int) $user->id, 404);

        $activateCompany->handle($user, (int) $run->company_id, $request);

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();

        try {
            $registrar->setPermissionsTeamId($run->company_id);
            abort_unless($user->can('bulk_documents.view'), 403);
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }

        return redirect()->route('organization.documents.generate', [
            'document_type_key' => 'custom_'.$run->document_generation_template_id,
        ]);
    }
}
