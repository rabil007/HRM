<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Models\CrewTimesheetPreparation;
use App\Models\PayrollPeriod;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Payroll\CrewTimeline\CrewTimelineDepartmentTree;
use App\Support\Payroll\CrewTimeline\CrewTimelinePagePermissions;
use App\Support\Payroll\CrewTimeline\CrewTimesheetPreparationReviewFilters;
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
        $filters = CrewTimesheetPreparationReviewFilters::fromRequest($request);

        $loaded = $reviewQuery->findForReview($payrollPeriod, (int) $preparation->id, $companyId);

        return Inertia::render('payroll/crew-timeline/show', [
            ...$reviewResource->toArray($payrollPeriod, $loaded, $filters),
            'search' => $filters->search,
            'filters' => [
                'department_id' => $filters->departmentId,
                'position_id' => $filters->positionId,
            ],
            'department_tree' => CrewTimelineDepartmentTree::for(
                $companyId,
                $loaded,
                new EmployeeDirectoryFilters(search: $filters->search),
            ),
            'department_tree_selected_id' => $filters->departmentId !== ''
                ? (int) $filters->departmentId
                : null,
            'department_tree_selected_position_id' => $filters->positionId !== ''
                ? (int) $filters->positionId
                : null,
            'permissions' => CrewTimelinePagePermissions::for($request->user()),
        ]);
    }
}
