<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Http\Controllers\Controller;
use App\Support\Documents\DocumentsModuleAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class RedirectLegacyBulkDocumentsController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $view = $request->query('view');

        if ($view === 'history') {
            return redirect()->route('organization.documents.activity');
        }

        if ($view === 'signatures') {
            if (DocumentsModuleAccess::canViewRequests($request->user())) {
                return redirect()->route('organization.documents.requests', [
                    'tab' => 'recipient',
                ]);
            }

            return redirect()->route('organization.documents.generate');
        }

        return redirect()->route('organization.documents.generate');
    }
}
