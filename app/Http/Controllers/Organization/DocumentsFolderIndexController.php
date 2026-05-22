<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\EmployeeDocuments\DocumentBrowseQuery;
use App\Support\EmployeeDocuments\DocumentExpiry;
use App\Support\EmployeeDocuments\DocumentPagePermissions;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DocumentsFolderIndexController extends Controller
{
    public function __invoke(Request $request, DocumentBrowseQuery $browse)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $search = trim((string) $request->query('search', ''));
        $expiry = (string) $request->query('expiry', 'all');

        if (! DocumentExpiry::isValidFilter($expiry)) {
            $expiry = 'all';
        }

        $summary = $browse->expirySummary($companyId);

        $payload = [
            'summary' => $summary,
            'expiry' => $expiry,
            'search' => $search,
            'employees' => [],
            'complianceDocuments' => null,
            'can' => DocumentPagePermissions::for($request->user()),
        ];

        if ($expiry === 'all') {
            $payload['employees'] = $browse->employeesWithDocuments(
                $companyId,
                $search !== '' ? $search : null,
            )->values()->all();
        } else {
            $payload['complianceDocuments'] = $browse->documentsForCompliance(
                $companyId,
                $expiry,
                $search !== '' ? $search : null,
            );
        }

        return Inertia::render('organization/documents/index', $payload);
    }
}
