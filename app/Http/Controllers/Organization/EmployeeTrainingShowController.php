<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Employee;
use App\Models\EmployeeTraining;
use App\Support\Activity\RecentActivityQuery;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateResolver;
use App\Support\Employees\EmployeeFormOptions;
use App\Support\EmployeeTrainings\TrainingAccess;
use App\Support\EmployeeTrainings\TrainingPagePermissions;
use App\Support\EmployeeTrainings\TrainingShowBackNavigation;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmployeeTrainingShowController extends Controller
{
    public function __invoke(Request $request, Employee $employee, EmployeeTraining $training)
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        TrainingAccess::assertEmployeeInCompany($employee, $companyId, 404);
        TrainingAccess::assertTrainingBelongsToEmployee($employee, $training, $companyId, 404);
        TrainingAccess::assertTrainingInCompany($training, $companyId, 404);

        $employee->loadMissing('employeeProfileTemplate:id,name,configuration_json');

        $training->load([
            'course:id,name',
            'country:id,name',
            'versions.replacer:id,name',
        ]);

        $templateConfiguration = EmployeeProfileTemplateResolver::resolve($employee->employeeProfileTemplate);

        return Inertia::render('organization/training/show', [
            'training' => $training->toShowArray(),
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
            ],
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
            'template_fields' => $templateConfiguration['fields']['employee_trainings'] ?? null,
            'can' => TrainingPagePermissions::for($request->user()),
            'back' => TrainingShowBackNavigation::resolve($request, $employee),
            'recent_activity' => RecentActivityQuery::for(
                $request->user(),
                $companyId,
                EmployeeTraining::class,
                $training->id,
                limit: 20,
            ),
            'can_view_audit' => $request->user()?->can('audit.view') ?? false,
        ]);
    }
}
