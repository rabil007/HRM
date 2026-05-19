<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDocument;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        $companyId = (int) request()->attributes->get('current_company_id');

        $counts = EmployeeDocument::query()
            ->where('company_id', $companyId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $uploadedThisMonth = EmployeeDocument::query()
            ->where('company_id', $companyId)
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        return Inertia::render('dashboard', [
            'document_compliance' => [
                'valid' => (int) ($counts['valid'] ?? 0),
                'expiring_soon' => (int) ($counts['expiring_soon'] ?? 0),
                'expired' => (int) ($counts['expired'] ?? 0),
                'uploaded_this_month' => $uploadedThisMonth,
            ],
        ]);
    }
}
