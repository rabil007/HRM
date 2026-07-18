<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\Dashboard\DashboardAnalytics;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(DashboardAnalytics $analytics): Response
    {
        $companyId = (int) request()->attributes->get('current_company_id');

        return Inertia::render('dashboard', [
            ...$analytics->primaryForCompany($companyId),
            'workforce_trends' => Inertia::defer(
                fn (): array => $analytics->workforceTrends($companyId),
                'secondary',
            ),
            'employees_by_department' => Inertia::defer(
                fn (): array => $analytics->employeesByDepartment($companyId),
                'secondary',
            ),
            'employees_by_branch' => Inertia::defer(
                fn (): array => $analytics->employeesByBranch($companyId),
                'secondary',
            ),
            'recent_hires' => Inertia::defer(
                fn (): array => $analytics->recentHires($companyId),
                'secondary',
            ),
        ]);
    }
}
