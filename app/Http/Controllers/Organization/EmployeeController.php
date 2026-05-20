<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Employee\StoreEmployeeRequest;
use App\Http\Requests\Organization\Employee\UpdateEmployeeRequest;
use App\Http\Requests\Organization\Employee\UpdateEmployeeStatusRequest;
use App\Models\Employee;
use App\Models\OnboardingTemplate;
use App\Support\Employees\Actions\CreateEmployee;
use App\Support\Employees\BuildDepartmentEmployeeTree;
use App\Support\Employees\EmployeeDirectoryFilters;
use App\Support\Employees\EmployeeDirectoryQuery;
use App\Support\Employees\EmployeeFormOptions;
use App\Support\Employees\Resources\EmployeeListResource;
use App\Support\Employees\Services\EmployeeProfilePageData;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class EmployeeController extends Controller
{
    use ResolvesPerPage;

    public function index()
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage(request());
        $directoryFilters = EmployeeDirectoryFilters::fromRequest(request());
        $formOptions = EmployeeFormOptions::for($companyId);

        $paginator = (new EmployeeDirectoryQuery($companyId, $directoryFilters))
            ->apply(
                Employee::query()->with([
                    'branch:id,name',
                    'department:id,name',
                    'position:id,title',
                    'manager:id,name,employee_no',
                    'user:id,name,email',
                    'religionRef:id,name',
                    'genderRef:id,name',
                    'nationalityRef:id,name,code',
                    'primaryBankAccount.bank:id,name',
                    'currentContract',
                ]),
            )
            ->paginate($perPage)
            ->withQueryString();

        $employees = $paginator->through(
            fn (Employee $employee) => EmployeeListResource::toArray($employee),
        );

        return Inertia::render('organization/employees', [
            'employees' => $employees->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $directoryFilters->search,
            'filters' => [
                'branch_id' => $directoryFilters->branchId,
                'department_id' => $directoryFilters->departmentId,
                'position_id' => $directoryFilters->positionId,
                'status' => $directoryFilters->status,
            ],
            'branches' => $formOptions['branches'],
            'departments' => $formOptions['departments'],
            'positions' => $formOptions['positions'],
            'managers' => $formOptions['managers'],
            'users' => $formOptions['users'],
            'countries' => $formOptions['countries'],
            'religions' => $formOptions['religions'],
            'genders' => $formOptions['genders'],
            'banks' => $formOptions['banks'],
            'department_tree' => BuildDepartmentEmployeeTree::for($companyId, $directoryFilters),
            'department_tree_selected_id' => $directoryFilters->departmentId !== '' ? (int) $directoryFilters->departmentId : null,
        ]);
    }

    public function create()
    {
        $companyId = (int) request()->attributes->get('current_company_id');

        $rankId = (int) request()->query('rank_id', 0);

        $allTemplates = OnboardingTemplate::query()
            ->where('company_id', $companyId)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'is_default', 'tasks']);

        if ($allTemplates->isEmpty()) {
            $message = 'No onboarding template found for this company. Please create one before adding employees.';

            if (request()->user()?->can('onboarding.templates.create')) {
                return redirect()
                    ->route('onboarding.templates.create')
                    ->with('error', $message);
            }

            return redirect()
                ->route('organization.employees')
                ->with('error', $message);
        }

        $requestedId = (int) request()->query('template_id', 0);

        $templateId = $requestedId && $allTemplates->contains('id', $requestedId)
            ? $requestedId
            : ($allTemplates->firstWhere('is_default', true)?->id ?? $allTemplates->first()?->id);

        $template = OnboardingTemplate::query()->find($templateId);

        return Inertia::render('organization/employee-create', [
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'description' => $template->description,
                'tasks' => $template->tasks,
            ],
            'selectedRankId' => $rankId > 0 ? $rankId : null,
            'allTemplates' => $allTemplates->map(fn (OnboardingTemplate $onboardingTemplate) => [
                'id' => $onboardingTemplate->id,
                'name' => $onboardingTemplate->name,
                'description' => $onboardingTemplate->description,
                'is_default' => $onboardingTemplate->is_default,
            ]),
            'options' => EmployeeFormOptions::forCreate($companyId),
        ]);
    }

    public function show(Employee $employee)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $employee->company_id === $companyId, 404);

        return Inertia::render(
            'organization/employee',
            EmployeeProfilePageData::for($employee, $companyId, request()),
        );
    }

    public function store(StoreEmployeeRequest $request, CreateEmployee $createEmployee)
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $createEmployee->handle(
            $request->validated(),
            $companyId,
            $request->user()?->id,
            $request->file('image'),
        );

        return redirect()
            ->route('organization.employees')
            ->with('success', 'Employee created successfully.');
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $employee->company_id === $companyId, 404);

        $data = $request->validated();
        $data['company_id'] = $companyId;

        if ($request->hasFile('image')) {
            if ($employee->image) {
                Storage::disk('public')->delete($employee->image);
            }

            $data['image'] = $request->file('image')->storePublicly(
                "employees/{$companyId}/images",
                ['disk' => 'public']
            );
        }

        if (($data['religion_id'] ?? null) === '') {
            $data['religion_id'] = null;
        }

        if (($data['gender_id'] ?? null) === '') {
            $data['gender_id'] = null;
        }

        if (($data['visa_type_id'] ?? null) === '') {
            $data['visa_type_id'] = null;
        }

        foreach ([
            'user_id',
            'branch_id',
            'department_id',
            'position_id',
            'rank_id',
            'manager_id',
            'date_of_birth',
            'nationality_id',
            'visa_type_id',
            'marital_status',
            'personal_email',
            'work_email',
            'phone',
            'emergency_contact',
            'emergency_phone',
            'address',
            'emirates_id',
            'passport_number',
            'labor_card_number',
            'termination_date',
            'termination_reason',
        ] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $data['status'] = $data['status'] ?? 'active';

        $employee->update($data);

        return redirect()
            ->route('organization.employees.show', $employee)
            ->with('success', 'Employee updated successfully.');
    }

    public function destroy(Employee $employee)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $employee->company_id === $companyId, 404);

        $employee->delete();

        return redirect()
            ->route('organization.employees')
            ->with('success', 'Employee deleted successfully.');
    }

    public function updateStatus(UpdateEmployeeStatusRequest $request, Employee $employee)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $employee->company_id === $companyId, 404);

        $employee->update([
            'status' => $request->validated('status'),
        ]);

        return redirect()
            ->route('organization.employees')
            ->with('success', 'Employee status updated successfully.');
    }
}
