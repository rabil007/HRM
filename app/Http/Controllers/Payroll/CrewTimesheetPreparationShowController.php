<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\CrewTimesheetPreparation;
use App\Models\PayrollPeriod;
use App\Support\Payroll\CrewTimeline\CrewTimelinePagePermissions;
use App\Support\Payroll\CrewTimeline\CrewTimesheetPreparationReviewQuery;
use App\Support\Payroll\CrewTimeline\CrewTimesheetPreparationReviewResource;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CrewTimesheetPreparationShowController extends Controller
{
    public function __invoke(
        Request $request,
        PayrollPeriod $payrollPeriod,
        CrewTimesheetPreparation $preparation,
        CrewTimesheetPreparationReviewQuery $reviewQuery,
        CrewTimesheetPreparationReviewResource $reviewResource,
    ): Response {
        $companyId = (int) $request->attributes->get('current_company_id');

        $loaded = $reviewQuery->findForReview($payrollPeriod, (int) $preparation->id, $companyId);

        return Inertia::render('payroll/crew-timeline/show', [
            ...$reviewResource->toArray($payrollPeriod, $loaded),
            'permissions' => CrewTimelinePagePermissions::for($request->user()),
        ]);
    }
}
