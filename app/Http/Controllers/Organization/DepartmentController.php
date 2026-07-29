<?php

namespace App\Http\Controllers\Organization;

use App\Exports\DepartmentsExport;
use App\Http\Controllers\Concerns\ReturnsQuickCreateJson;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Department\StoreDepartmentRequest;
use App\Http\Requests\Organization\Department\UpdateDepartmentRequest;
use App\Http\Requests\Organization\Department\UpdateDepartmentStatusRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveApprovalPolicy;
use App\Models\Position;
use App\Support\Activity\RecentActivityQuery;
use App\Support\Departments\DepartmentHierarchyContext;
use App\Support\Departments\PresentDepartmentEffectiveFields;
use App\Support\Employees\EmployeeFormOptions;
use App\Support\Pagination\ResolvesPerPage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

class DepartmentController extends Controller
{
    use ResolvesPerPage;
    use ReturnsQuickCreateJson;

    public function index()
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage(request());
        $search = trim((string) request()->query('search', ''));
        $id = trim((string) request()->query('id', ''));
        $branchId = trim((string) request()->query('branch_id', ''));
        $parentId = trim((string) request()->query('parent_id', ''));
        $managerId = trim((string) request()->query('manager_id', ''));
        $status = trim((string) request()->query('status', ''));
        $code = trim((string) request()->query('code', ''));

        $branches = Branch::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        $parents = Department::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'parent_id', 'name']);

        $managers = EmployeeFormOptions::managersForSelect($companyId);

        $leaveApprovalPolicies = LeaveApprovalPolicy::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'is_default']);

        $paginator = Department::query()
            ->with([
                'branch:id,name',
                'parent:id,name',
                'manager:id,name,employee_no',
                'leaveApprovalPolicy:id,name',
            ])
            ->where('company_id', $companyId)
            ->when($id, fn ($q) => $q->where('id', $id))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($parentId, fn ($q) => $q->where(function ($inner) use ($parentId) {
                $inner->where('id', $parentId)->orWhere('parent_id', $parentId);
            }))
            ->when($managerId, fn ($q) => $q->where('manager_id', $managerId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($code, fn ($q) => $q->where('code', 'like', "%{$code}%"))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        $departmentsById = Department::query()
            ->where('company_id', $companyId)
            ->with([
                'manager:id,company_id,name,employee_no,user_id,status',
                'leaveApprovalPolicy:id,company_id,name,status,is_default',
            ])
            ->get(['id', 'company_id', 'parent_id', 'manager_id', 'leave_approval_policy_id', 'name', 'code', 'status'])
            ->keyBy('id');

        $hierarchyContext = DepartmentHierarchyContext::fromDepartments($companyId, $departmentsById);

        $departments = $paginator->through(function (Department $department) use ($hierarchyContext) {
            $effective = PresentDepartmentEffectiveFields::forDepartmentWithContext($department, $hierarchyContext);

            return [
                'id' => $department->id,
                'company' => [
                    'id' => $department->company_id,
                    'name' => null,
                ],
                'branch' => $department->branch_id ? [
                    'id' => $department->branch_id,
                    'name' => $department->branch?->name,
                ] : null,
                'parent' => $department->parent_id ? [
                    'id' => $department->parent_id,
                    'name' => $department->parent?->name,
                ] : null,
                'manager' => $effective['manager'],
                'manager_assignment' => $effective['manager_assignment'],
                'leave_approval_policy_id' => $department->leave_approval_policy_id,
                'leave_approval_policy' => $effective['leave_approval_policy'],
                'leave_approval_policy_assignment' => $effective['leave_approval_policy_assignment'],
                'name' => $department->name,
                'code' => $department->code,
                'status' => $department->status,
                'created_at' => $department->created_at,
            ];
        });

        $allDepartments = Department::query()
            ->with([
                'branch:id,name',
                'parent:id,name',
                'manager:id,name,employee_no',
                'leaveApprovalPolicy:id,name',
            ])
            ->where('company_id', $companyId)
            ->when($id, fn ($q) => $q->where('id', $id))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($parentId, fn ($q) => $q->where(function ($inner) use ($parentId) {
                $inner->where('id', $parentId)->orWhere('parent_id', $parentId);
            }))
            ->when($managerId, fn ($q) => $q->where('manager_id', $managerId))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($code, fn ($q) => $q->where('code', 'like', "%{$code}%"))
            ->when($search, function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%");
                });
            })
            ->withCount(['positions', 'employees as users_count'])
            ->orderBy('name')
            ->get()
            ->map(function ($department) use ($hierarchyContext) {
                $effective = PresentDepartmentEffectiveFields::forDepartmentWithContext($department, $hierarchyContext);

                return [
                    'id' => $department->id,
                    'parent_id' => $department->parent_id,
                    'name' => $department->name,
                    'code' => $department->code,
                    'status' => $department->status,
                    'manager' => $effective['manager'],
                    'manager_assignment' => $effective['manager_assignment'],
                    'leave_approval_policy' => $effective['leave_approval_policy'],
                    'leave_approval_policy_assignment' => $effective['leave_approval_policy_assignment'],
                    'branch' => $department->branch_id ? [
                        'id' => $department->branch_id,
                        'name' => $department->branch?->name,
                    ] : null,
                    'positions_count' => $department->positions_count,
                    'users_count' => $department->users_count,
                ];
            });

        return Inertia::render('organization/departments', [
            'departments' => $departments->items(),
            'all_departments' => $allDepartments,
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'filters' => [
                'id' => $id,
                'branch_id' => $branchId,
                'parent_id' => $parentId,
                'manager_id' => $managerId,
                'status' => $status,
                'code' => $code,
            ],
            'branches' => $branches,
            'parents' => $parents,
            'managers' => $managers,
            'leave_approval_policies' => $leaveApprovalPolicies,
        ]);
    }

    public function show(Department $department)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $department->company_id === $companyId, 404);

        $positionsCount = Position::query()
            ->where('company_id', $companyId)
            ->where('department_id', $department->id)
            ->count();

        $usersCount = Employee::query()
            ->where('company_id', $companyId)
            ->where('department_id', $department->id)
            ->count();

        $branches = Branch::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        $parents = Department::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        $managers = EmployeeFormOptions::managersForSelect($companyId);

        $leaveApprovalPolicies = LeaveApprovalPolicy::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get(['id', 'name', 'is_default']);

        $department->load([
            'branch:id,name',
            'parent:id,name',
            'manager:id,name,employee_no',
            'leaveApprovalPolicy:id,name',
        ]);

        $departmentsById = Department::query()
            ->where('company_id', $companyId)
            ->with([
                'manager:id,company_id,name,employee_no,user_id,status',
                'leaveApprovalPolicy:id,company_id,name,status,is_default',
            ])
            ->get(['id', 'company_id', 'parent_id', 'manager_id', 'leave_approval_policy_id', 'name', 'code', 'status'])
            ->keyBy('id');

        $hierarchyContext = DepartmentHierarchyContext::fromDepartments($companyId, $departmentsById);
        $effective = PresentDepartmentEffectiveFields::forDepartmentWithContext($department, $hierarchyContext);

        $childDepartments = Department::query()
            ->where('company_id', $companyId)
            ->where('parent_id', $department->id)
            ->withCount([
                'positions as positions_count' => fn ($q) => $q->where('company_id', $companyId),
                'employees as users_count' => fn ($q) => $q->where('company_id', $companyId),
            ])
            ->get()
            ->map(fn ($child) => [
                'id' => $child->id,
                'name' => $child->name,
                'code' => $child->code,
                'positions_count' => $child->positions_count,
                'users_count' => $child->users_count,
            ]);

        $request = request();

        return Inertia::render('organization/department', [
            'department' => [
                'id' => $department->id,
                'company' => [
                    'id' => $department->company_id,
                    'name' => null,
                    'slug' => null,
                ],
                'branch' => $department->branch_id ? [
                    'id' => $department->branch_id,
                    'name' => $department->branch?->name,
                ] : null,
                'parent' => $department->parent_id ? [
                    'id' => $department->parent_id,
                    'name' => $department->parent?->name,
                ] : null,
                'manager' => $effective['manager'],
                'manager_assignment' => $effective['manager_assignment'],
                'leave_approval_policy_id' => $department->leave_approval_policy_id,
                'leave_approval_policy' => $effective['leave_approval_policy'],
                'leave_approval_policy_assignment' => $effective['leave_approval_policy_assignment'],
                'name' => $department->name,
                'code' => $department->code,
                'status' => $department->status,
                'positions_count' => $positionsCount,
                'users_count' => $usersCount,
                'branches_count' => $branches->count(),
                'created_at' => $department->created_at,
                'updated_at' => $department->updated_at,
            ],
            'child_departments' => $childDepartments,
            'branches' => $branches,
            'parents' => $parents,
            'managers' => $managers,
            'leave_approval_policies' => $leaveApprovalPolicies,
            'recent_activity' => RecentActivityQuery::for(
                $request->user(),
                $companyId,
                Department::class,
                $department->id,
            ),
            'can_view_audit' => $request->user()?->can('audit.view') ?? false,
        ]);
    }

    public function store(StoreDepartmentRequest $request): JsonResponse|RedirectResponse
    {
        $data = $request->validated();
        $companyId = (int) $request->attributes->get('current_company_id');
        $data['company_id'] = $companyId;

        foreach (['code', 'leave_approval_policy_id'] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $data['status'] = $data['status'] ?? 'active';

        return $this->createOrReturnExistingQuickCreate(
            $request,
            Department::class,
            $data,
            redirect()
                ->route('organization.departments')
                ->with('success', 'Department created successfully.'),
            'name',
            ['company_id' => $companyId],
        );
    }

    public function update(UpdateDepartmentRequest $request, Department $department)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $department->company_id === $companyId, 404);

        $data = $request->validated();
        $data['company_id'] = $companyId;

        foreach (['code', 'leave_approval_policy_id'] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $data['status'] = $data['status'] ?? 'active';

        $department->update($data);

        return redirect()
            ->route('organization.departments')
            ->with('success', 'Department updated successfully.');
    }

    public function destroy(Department $department)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $department->company_id === $companyId, 404);

        $department->delete();

        return redirect()
            ->route('organization.departments')
            ->with('success', 'Department deleted successfully.');
    }

    public function updateStatus(UpdateDepartmentStatusRequest $request, Department $department)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $department->company_id === $companyId, 404);

        $department->update([
            'status' => $request->validated('status'),
        ]);

        return redirect()
            ->route('organization.departments')
            ->with('success', 'Department status updated successfully.');
    }

    public function export(Request $request)
    {
        $format = strtolower((string) $request->query('format', 'csv'));

        $search = trim((string) $request->query('search', ''));
        $companyId = (int) $request->attributes->get('current_company_id');
        $branchId = trim((string) $request->query('branch_id', ''));
        $parentId = trim((string) $request->query('parent_id', ''));
        $managerId = trim((string) $request->query('manager_id', ''));
        $status = trim((string) $request->query('status', ''));

        $query = Department::query()
            ->with(['branch:id,name', 'parent:id,name', 'manager:id,name,employee_no'])
            ->where('company_id', $companyId)
            ->latest('id');

        if ($branchId !== '') {
            $query->where('branch_id', $branchId);
        }

        if ($parentId !== '') {
            $query->where('parent_id', $parentId);
        }

        if ($managerId !== '') {
            $query->where('manager_id', $managerId);
        }

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('branch', fn ($bq) => $bq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('parent', fn ($pq) => $pq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('manager', fn ($mq) => $mq->where('name', 'like', "%{$search}%"));
            });
        }

        $companyName = Company::query()->whereKey($companyId)->value('name');
        $export = new DepartmentsExport($query, $companyName);

        $timestamp = now()->format('Y-m-d_His');
        $baseName = "departments_{$timestamp}";

        if ($format === 'xlsx' || $format === 'excel') {
            return Excel::download($export, "{$baseName}.xlsx", ExcelWriter::XLSX);
        }

        if ($format === 'pdf') {
            $departments = $query->get();
            $pdf = Pdf::loadView('exports.departments', [
                'departments' => $departments,
                'companyName' => $companyName,
                'generatedAt' => now(),
            ]);

            return $pdf->download("{$baseName}.pdf");
        }

        return Excel::download($export, "{$baseName}.csv", ExcelWriter::CSV, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
