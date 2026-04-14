<?php

namespace App\Http\Controllers\Organization;

use App\Exports\RolesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\Role\StoreRoleRequest;
use App\Http\Requests\Organization\Role\UpdateRoleRequest;
use App\Models\Company;
use App\Models\Role;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

class RoleController extends Controller
{
    public function index()
    {
        $companies = Company::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $roles = Role::query()
            ->with(['company:id,name'])
            ->latest('id')
            ->paginate(20)
            ->through(fn (Role $role) => [
                'id' => $role->id,
                'company' => [
                    'id' => $role->company_id,
                    'name' => $role->company?->name,
                ],
                'name' => $role->name,
                'slug' => $role->slug,
                'permissions' => $role->permissions ?? [],
                'is_system' => (bool) $role->is_system,
                'created_at' => $role->created_at,
            ]);

        return Inertia::render('organization/roles', [
            'roles' => $roles,
            'companies' => $companies,
        ]);
    }

    public function show(Role $role)
    {
        $companies = Company::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $role->load(['company:id,name,slug']);

        return Inertia::render('organization/role', [
            'role' => [
                'id' => $role->id,
                'company' => [
                    'id' => $role->company_id,
                    'name' => $role->company?->name,
                    'slug' => $role->company?->slug,
                ],
                'name' => $role->name,
                'slug' => $role->slug,
                'permissions' => $role->permissions ?? [],
                'is_system' => (bool) $role->is_system,
                'created_at' => $role->created_at,
                'updated_at' => $role->updated_at,
            ],
            'companies' => $companies,
        ]);
    }

    public function store(StoreRoleRequest $request)
    {
        $data = $request->validated();

        $data['permissions'] = array_values(array_filter(array_map('strval', $data['permissions'] ?? [])));
        $data['is_system'] = (bool) ($data['is_system'] ?? false);
        $data['slug'] = Str::of((string) $data['slug'])->slug()->substr(0, 100)->toString();

        Role::create($data);

        return redirect()->route('organization.roles');
    }

    public function update(UpdateRoleRequest $request, Role $role)
    {
        $data = $request->validated();

        $data['permissions'] = array_values(array_filter(array_map('strval', $data['permissions'] ?? [])));
        $data['is_system'] = (bool) ($data['is_system'] ?? false);
        $data['slug'] = Str::of((string) $data['slug'])->slug()->substr(0, 100)->toString();

        $role->update($data);

        return redirect()->route('organization.roles');
    }

    public function destroy(Role $role)
    {
        if ($role->is_system) {
            return redirect()->route('organization.roles');
        }

        $role->delete();

        return redirect()->route('organization.roles');
    }

    public function export(Request $request)
    {
        $format = strtolower((string) $request->query('format', 'csv'));

        $search = trim((string) $request->query('search', ''));
        $companyId = trim((string) $request->query('company_id', ''));
        $isSystem = trim((string) $request->query('is_system', ''));

        $query = Role::query()
            ->with(['company:id,name'])
            ->latest('id');

        if ($companyId !== '') {
            $query->where('company_id', $companyId);
        }

        if ($isSystem !== '') {
            $query->where('is_system', filter_var($isSystem, FILTER_VALIDATE_BOOL));
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhereHas('company', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
            });
        }

        $export = new RolesExport($query);

        $timestamp = now()->format('Y-m-d_His');
        $baseName = "roles_{$timestamp}";

        if ($format === 'xlsx' || $format === 'excel') {
            return Excel::download($export, "{$baseName}.xlsx", ExcelWriter::XLSX);
        }

        if ($format === 'pdf') {
            $roles = $query->get();
            $pdf = Pdf::loadView('exports.roles', [
                'roles' => $roles,
                'generatedAt' => now(),
            ]);

            return $pdf->download("{$baseName}.pdf");
        }

        return Excel::download($export, "{$baseName}.csv", ExcelWriter::CSV, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
