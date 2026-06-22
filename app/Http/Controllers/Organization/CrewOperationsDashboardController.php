<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Support\CrewOperations\CrewOperationsDashboardAnalytics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrewOperationsDashboardController extends Controller
{
    public function __invoke(Request $request, CrewOperationsDashboardAnalytics $analytics): Response
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        return Inertia::render('organization/crew-operations/index', $analytics->forCompany(
            $companyId,
            $request->user(),
        ));
    }
}
