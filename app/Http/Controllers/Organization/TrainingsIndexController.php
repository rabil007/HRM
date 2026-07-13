<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeFormOptions;
use App\Support\EmployeeTrainings\TrainingDepartmentTree;
use App\Support\EmployeeTrainings\TrainingDirectoryFilters;
use App\Support\EmployeeTrainings\TrainingDirectoryQuery;
use App\Support\EmployeeTrainings\TrainingPagePermissions;
use App\Support\EmployeeTrainings\TrainingSummaryQuery;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TrainingsIndexController extends Controller
{
    use ResolvesPerPage;

    public function __invoke(Request $request, TrainingSummaryQuery $summaryQuery)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $filters = TrainingDirectoryFilters::fromRequest($request);
        $perPage = $this->resolvePerPage($request, default: 25);

        $paginator = (new TrainingDirectoryQuery($companyId, $filters))->paginate($perPage);

        return Inertia::render('organization/training/index', [
            'summary' => $summaryQuery->forCompany($companyId),
            'expiry' => $filters->expiry,
            'search' => $filters->search,
            'issue_date' => $filters->issueDate,
            'branch_id' => $filters->branchId,
            'department_id' => $filters->departmentId,
            'department_tree' => TrainingDepartmentTree::for(
                $companyId,
                new EmployeeDirectoryFilters(departmentId: $filters->departmentId),
            ),
            'department_tree_selected_id' => $filters->departmentId !== '' ? (int) $filters->departmentId : null,
            'trainings' => $paginator->items(),
            'pagination' => $this->paginationMeta($paginator),
            'courses' => Course::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Course $course) => [
                    'id' => $course->id,
                    'name' => $course->name,
                ])
                ->values()
                ->all(),
            'countries' => EmployeeFormOptions::for($companyId)['countries'],
            'can' => TrainingPagePermissions::for($request->user()),
        ]);
    }
}
