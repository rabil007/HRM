<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Employee;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateResolver;
use App\Support\Employees\EmployeeFormOptions;
use App\Support\EmployeeTrainings\TrainingAccess;
use App\Support\EmployeeTrainings\TrainingEmployeeBrowseQuery;
use App\Support\EmployeeTrainings\TrainingPagePermissions;
use App\Support\EmployeeTrainings\TrainingShowBackNavigation;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EmployeeTrainingsBrowseController extends Controller
{
    public function __invoke(Request $request, Employee $employee, TrainingEmployeeBrowseQuery $browse)
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        TrainingAccess::assertEmployeeInCompany($employee, $companyId, 404);

        $employee->load('employeeProfileTemplate:id,name,configuration_json');

        $result = $browse->forEmployee($companyId, $employee);
        $resolved = EmployeeProfileTemplateResolver::resolve($employee->employeeProfileTemplate);

        return Inertia::render('organization/training/employee', [
            'employee' => $result['employee'],
            'trainings' => $result['trainings'],
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
            'template_fields' => $resolved['fields']['employee_trainings'] ?? null,
            'back' => TrainingShowBackNavigation::resolveIndex($request),
            'can' => TrainingPagePermissions::for($request->user()),
        ]);
    }
}
