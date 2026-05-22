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

        return Inertia::render('dashboard', $analytics->forCompany($companyId));
    }
}
