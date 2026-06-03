<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Employee\AssignEmployeeProfileTemplateRequest;
use App\Http\Requests\Organization\Employee\StoreEmployeeRequest;
use App\Http\Requests\Organization\Employee\StoreEnsureEmployeeRequest;
use App\Http\Requests\Organization\Employee\UpdateEmployeeRequest;
use App\Http\Requests\Organization\Employee\UpdateEmployeeStatusRequest;
use App\Models\Employee;
use App\Models\EmployeeProfileTemplate;
use App\Support\EmployeeProfileTemplates\EmployeeProfileTemplateRequestRules;
use App\Support\Employees\Actions\CreateEmployee;
use App\Support\Employees\Actions\CreateEmployeeFromName;
use App\Support\Employees\Actions\SyncEmployeeWorkAssignments;
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
                'manager_id' => $directoryFilters->managerId,
                'gender_id' => $directoryFilters->genderId,
                'nationality_id' => $directoryFilters->nationalityId,
                'visa_type_id' => $directoryFilters->visaTypeId,
                'company_visa_type_id' => $directoryFilters->companyVisaTypeId,
                'rank_id' => $directoryFilters->rankId,
                'approval_location_id' => $directoryFilters->approvalLocationId,
                'sssa_option_id' => $directoryFilters->sssaOptionId,
            ],
            'branches' => $formOptions['branches'],
            'departments' => $formOptions['departments'],
            'positions' => $formOptions['positions'],
            'managers' => $formOptions['managers'],
            'users' => $formOptions['users'],
            'countries' => $formOptions['countries'],
            'religions' => $formOptions['religions'],
            'genders' => $formOptions['genders'],
            'visa_types' => $formOptions['visa_types'],
            'company_visa_types' => $formOptions['company_visa_types'],
            'approval_locations' => $formOptions['approval_locations'],
            'sssa_options' => $formOptions['sssa_options'],
            'ranks' => $formOptions['ranks'],
            'banks' => $formOptions['banks'],
            'department_tree' => BuildDepartmentEmployeeTree::for($companyId, $directoryFilters),
            'department_tree_selected_id' => $directoryFilters->departmentId !== '' ? (int) $directoryFilters->departmentId : null,
            'department_tree_selected_position_id' => $directoryFilters->positionId !== '' ? (int) $directoryFilters->positionId : null,
        ]);
    }

    public function create()
    {
        $companyId = (int) request()->attributes->get('current_company_id');

        $requestedTemplateId = (int) request()->query('profile_template_id', 0);
        $selectedTemplate = $requestedTemplateId > 0
            ? EmployeeProfileTemplate::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->find($requestedTemplateId)
            : null;

        $employee = null;
        $employeeId = (int) request()->query('employee_id', 0);
        if ($employeeId > 0) {
            $employee = Employee::query()
                ->where('company_id', $companyId)
                ->where('id', $employeeId)
                ->first();
            abort_unless($employee instanceof Employee, 404);
            $employee->load([
                'branch:id,name',
                'department:id,name',
                'position:id,title',
                'rank:id,name',
                'manager:id,name,employee_no',
                'user:id,name,email,avatar',
                'religionRef:id,name',
                'genderRef:id,name',
                'visaTypeRef:id,name',
                'companyVisaTypeRef:id,name',
                'nationalityRef:id,name,code',
                'bankAccounts.bank:id,name',
                'primaryBankAccount.bank:id,name',
                'currentContract',
                'employeeProfileTemplate:id,name,configuration_json',
            ]);
        }

        return Inertia::render(
            'organization/employee',
            EmployeeProfilePageData::forCreate($companyId, request(), $employee, $selectedTemplate),
        );
    }

    public function ensure(StoreEnsureEmployeeRequest $request, CreateEmployeeFromName $createEmployeeFromName)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $validated = $request->validated();

        $employee = $createEmployeeFromName->handle(
            $validated['name'],
            $companyId,
            isset($validated['employee_profile_template_id'])
                ? (int) $validated['employee_profile_template_id']
                : null,
        );

        return response()->json([
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'employee_no' => $employee->employee_no,
            ],
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

        $employee->loadMissing('employeeProfileTemplate');

        $validated = $request->validated();
        $removeImage = $request->boolean('remove_image');
        unset($validated['remove_image']);

        $data = EmployeeProfileTemplateRequestRules::onlyVisibleAttributes(
            $employee,
            'employees',
            $validated,
        );
        $data['company_id'] = $companyId;

        if ($request->hasFile('image')) {
            if ($employee->image) {
                Storage::disk('public')->delete($employee->image);
            }

            $data['image'] = $request->file('image')->storePublicly(
                "employees/{$companyId}/images",
                ['disk' => 'public']
            );
        } elseif ($removeImage) {
            if ($employee->image) {
                Storage::disk('public')->delete($employee->image);
            }

            $data['image'] = null;
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

        if (($data['company_visa_type_id'] ?? null) === '') {
            $data['company_visa_type_id'] = null;
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
            'company_visa_type_id',
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

        $approvalLocationIds = $data['approval_location_ids'] ?? null;
        $sssaOptionIds = $data['sssa_option_ids'] ?? null;
        unset($data['approval_location_ids'], $data['sssa_option_ids']);

        $employee->update($data);

        SyncEmployeeWorkAssignments::sync($employee, array_filter([
            'approval_location_ids' => $approvalLocationIds,
            'sssa_option_ids' => $sssaOptionIds,
        ], fn ($value) => $value !== null));

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

    public function assignProfileTemplate(
        AssignEmployeeProfileTemplateRequest $request,
        Employee $employee,
    ) {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $employee->company_id === $companyId, 404);

        $employee->update([
            'employee_profile_template_id' => (int) $request->validated('employee_profile_template_id'),
        ]);

        return redirect()
            ->route('organization.employees.show', $employee)
            ->with('success', 'Profile template assigned successfully.');
    }
}
