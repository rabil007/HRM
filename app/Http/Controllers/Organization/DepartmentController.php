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
use App\Models\Position;
use App\Support\Activity\RecentActivityQuery;
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

        $paginator = Department::query()
            ->with([
                'branch:id,name',
                'parent:id,name',
                'manager:id,name,employee_no',
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

        $departments = $paginator->through(fn (Department $department) => [
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
            'manager' => $department->manager_id ? [
                'id' => $department->manager_id,
                'name' => $department->manager?->name,
            ] : null,
            'name' => $department->name,
            'code' => $department->code,
            'status' => $department->status,
            'created_at' => $department->created_at,
        ]);

        $allDepartments = Department::query()
            ->with([
                'branch:id,name',
                'parent:id,name',
                'manager:id,name,employee_no',
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
            ->map(fn ($department) => [
                'id' => $department->id,
                'parent_id' => $department->parent_id,
                'name' => $department->name,
                'code' => $department->code,
                'status' => $department->status,
                'manager' => $department->manager_id ? [
                    'id' => $department->manager_id,
                    'name' => $department->manager?->name,
                ] : null,
                'branch' => $department->branch_id ? [
                    'id' => $department->branch_id,
                    'name' => $department->branch?->name,
                ] : null,
                'positions_count' => $department->positions_count,
                'users_count' => $department->users_count,
            ]);

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

        $department->load([
            'branch:id,name',
            'parent:id,name',
            'manager:id,name,employee_no',
        ]);

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
                'manager' => $department->manager_id ? [
                    'id' => $department->manager_id,
                    'name' => $department->manager?->name,
                ] : null,
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

        foreach (['code'] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $data['status'] = $data['status'] ?? 'active';

        if (! empty($data['parent_id'])) {
            $data['manager_id'] = null;
        }

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

        foreach (['code'] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $data['status'] = $data['status'] ?? 'active';

        if (! empty($data['parent_id'])) {
            $data['manager_id'] = null;
        }

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
