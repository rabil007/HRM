<?php

namespace App\Http\Controllers\Organization;

use App\Exports\DepartmentsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Department\StoreDepartmentRequest;
use App\Http\Requests\Organization\Department\UpdateDepartmentRequest;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Department;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

class DepartmentController extends Controller
{
    public function index()
    {
        $companies = Company::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $branches = Branch::query()
            ->with(['company:id,name'])
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        $parents = Department::query()
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        $managers = User::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $departments = Department::query()
            ->with([
                'company:id,name',
                'branch:id,name',
                'parent:id,name',
                'manager:id,name',
            ])
            ->latest('id')
            ->paginate(20)
            ->through(fn (Department $department) => [
                'id' => $department->id,
                'company' => [
                    'id' => $department->company_id,
                    'name' => $department->company?->name,
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
            'companies' => $companies,
            'branches' => $branches,
            'parents' => $parents,
            'managers' => $managers,
        ]);
    }

    public function show(Department $department)
    {
        $companies = Company::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $branches = Branch::query()
            ->with(['company:id,name'])
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        $parents = Department::query()
            ->orderBy('name')
            ->get(['id', 'company_id', 'name']);

        $managers = User::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $department->load([
            'company:id,name,slug',
            'branch:id,name',
            'parent:id,name',
            'manager:id,name',
        ]);

        return Inertia::render('organization/department', [
            'department' => [
                'id' => $department->id,
                'company' => [
                    'id' => $department->company_id,
                    'name' => $department->company?->name,
                    'slug' => $department->company?->slug,
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
            'companies' => $companies,
            'branches' => $branches,
            'parents' => $parents,
            'managers' => $managers,
        ]);
    }

    public function store(StoreDepartmentRequest $request)
    {
        $data = $request->validated();

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
        $data = $request->validated();

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
        $department->delete();

        return redirect()->route('organization.departments');
    }

    public function export(Request $request)
    {
        $format = strtolower((string) $request->query('format', 'csv'));

        $search = trim((string) $request->query('search', ''));
        $companyId = trim((string) $request->query('company_id', ''));
        $branchId = trim((string) $request->query('branch_id', ''));
        $parentId = trim((string) $request->query('parent_id', ''));
        $managerId = trim((string) $request->query('manager_id', ''));
        $status = trim((string) $request->query('status', ''));

        $query = Department::query()
            ->with(['company:id,name', 'branch:id,name', 'parent:id,name', 'manager:id,name'])
            ->latest('id');

        if ($companyId !== '') {
            $query->where('company_id', $companyId);
        }

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
