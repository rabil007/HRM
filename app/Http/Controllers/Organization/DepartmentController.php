<?php

namespace App\Http\Controllers\Organization;

use App\Exports\DepartmentsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Department\StoreDepartmentRequest;
use App\Http\Requests\Organization\Department\UpdateDepartmentRequest;
use App\Models\Branch;
use App\Models\Department;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Activitylog\Models\Activity;

class DepartmentController extends Controller
{
    public function index()
    {
        $companyId = (int) request()->attributes->get('current_company_id');

        $branches = Branch::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        $parents = Department::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        $managers = User::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $departments = Department::query()
            ->with([
                'branch:id,name',
                'parent:id,name',
                'manager:id,name',
            ])
            ->where('company_id', $companyId)
            ->latest('id')
            ->paginate(20)
            ->through(fn (Department $department) => [
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

        return Inertia::render('organization/departments', [
            'departments' => $departments,
            'branches' => $branches,
            'parents' => $parents,
            'managers' => $managers,
        ]);
    }

    public function show(Department $department)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $department->company_id === $companyId, 404);

        $branches = Branch::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        $parents = Department::query()
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        $managers = User::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $department->load([
            'branch:id,name',
            'parent:id,name',
            'manager:id,name',
        ]);

        $recentActivity = [];
        $request = request();
        if ($request->user()?->can('audit.view')) {
            $recentActivity = Activity::query()
                ->where('company_id', $companyId)
                ->where('subject_type', Department::class)
                ->where('subject_id', $department->id)
                ->with(['causer:id,name,email'])
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (Activity $log) => [
                    'id' => $log->id,
                    'event' => $log->event,
                    'description' => $log->description,
                    'causer' => $log->causer ? [
                        'id' => $log->causer->id,
                        'name' => $log->causer->name,
                        'email' => $log->causer->email,
                    ] : null,
                    'old_values' => $log->attribute_changes?->get('old'),
                    'new_values' => $log->attribute_changes?->get('attributes'),
                    'created_at' => $log->created_at,
                ])
                ->all();
        }

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
                'created_at' => $department->created_at,
                'updated_at' => $department->updated_at,
            ],
            'branches' => $branches,
            'parents' => $parents,
            'managers' => $managers,
            'recent_activity' => $recentActivity,
        ]);
    }

    public function store(StoreDepartmentRequest $request)
    {
        $data = $request->validated();
        $data['company_id'] = (int) $request->attributes->get('current_company_id');

        foreach (['code'] as $key) {
            if (($data[$key] ?? null) === '') {
                $data[$key] = null;
            }
        }

        $data['status'] = $data['status'] ?? 'active';

        Department::create($data);

        return redirect()->route('organization.departments');
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

        $department->update($data);

        return redirect()->route('organization.departments');
    }

    public function destroy(Department $department)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $department->company_id === $companyId, 404);

        $department->delete();

        return redirect()->route('organization.departments');
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
            ->with(['branch:id,name', 'parent:id,name', 'manager:id,name'])
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

        $export = new DepartmentsExport($query);

        $timestamp = now()->format('Y-m-d_His');
        $baseName = "departments_{$timestamp}";

        if ($format === 'xlsx' || $format === 'excel') {
            return Excel::download($export, "{$baseName}.xlsx", ExcelWriter::XLSX);
        }

        if ($format === 'pdf') {
            $departments = $query->get();
            $pdf = Pdf::loadView('exports.departments', [
                'departments' => $departments,
                'generatedAt' => now(),
            ]);

            return $pdf->download("{$baseName}.pdf");
        }

        return Excel::download($export, "{$baseName}.csv", ExcelWriter::CSV, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
