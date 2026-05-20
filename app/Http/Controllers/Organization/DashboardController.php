<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDocument;
use App\Support\EmployeeDocuments\DocumentBrowseQuery;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(DocumentBrowseQuery $browse): Response
    {
        $companyId = (int) request()->attributes->get('current_company_id');

        $summary = $browse->expirySummary($companyId);

        $uploadedThisMonth = (int) EmployeeDocument::query()
            ->where('company_id', $companyId)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        return Inertia::render('dashboard', [
            'document_compliance' => [
                'total_documents' => $summary['total_documents'],
                'expired' => $summary['expired'],
                'expiring_30' => $summary['expiring_30'],
                'expiring_15' => $summary['expiring_15'],
                'expiring_7' => $summary['expiring_7'],
                'uploaded_this_month' => $uploadedThisMonth,
            ],
        ]);
    }
}
