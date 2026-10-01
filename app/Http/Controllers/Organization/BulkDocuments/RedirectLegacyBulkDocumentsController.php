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
        $legacyView = $request->route('legacy_view');
        $view = $legacyView === 'history' ? 'history' : $request->query('view');

        if ($view === 'history' || $view === 'activity') {
            $query = array_filter([
                'view' => 'activity',
                'document_type_key' => $request->query('document_type_key'),
                'search' => $request->query('search'),
                'department_id' => $request->query('department_id'),
                'position_id' => $request->query('position_id'),
                'company_visa_type_id' => $request->query('company_visa_type_id'),
                'email_filter' => $request->query('email_filter'),
                'process_filter' => $request->query('process_filter'),
                'generation_filter' => $request->query('generation_filter'),
                'per_page' => $request->query('per_page'),
                'page' => $request->query('page'),
            ], static fn ($value): bool => $value !== null && $value !== '');

            return redirect()->route('organization.documents.generate', $query);
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
