<?php

namespace App\Http\Controllers\Organization;

use App\Exports\RolesExport;
use App\Http\Controllers\Controller;
use App\Models\Company;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        $companyId = (int) request()->attributes->get('current_company_id');

        $roles = Role::query()
            ->where('company_id', $companyId)
            ->latest('id')
            ->paginate(20)
            ->through(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions()->pluck('name')->all(),
                'created_at' => $role->created_at,
            ]);

        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get(['id', 'name']);

        $company = Company::query()->whereKey($companyId)->first(['id', 'name']);

        return Inertia::render('organization/roles', [
            'roles' => $roles,
            'company' => $company,
            'permissions' => $permissions,
        ]);
    }

    public function show(Role $role)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $role->company_id === $companyId, 404);

        $permissions = Permission::query()
            ->where('guard_name', 'web')
            ->orderBy('name')
            ->get(['id', 'name']);

        $company = Company::query()->whereKey($companyId)->first(['id', 'name', 'slug']);

        return Inertia::render('organization/role', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions()->pluck('name')->all(),
                'created_at' => $role->created_at,
                'updated_at' => $role->updated_at,
            ],
            'company' => $company,
            'permissions' => $permissions,
        ]);
    }

    public function store(Request $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:100'],
        ]);

        foreach (($data['permissions'] ?? []) as $permissionName) {
            Permission::findOrCreate($permissionName, 'web');
        }

        $role = Role::query()->create([
            'company_id' => $companyId,
            'name' => $data['name'],
            'guard_name' => 'web',
        ]);

        $role->syncPermissions($data['permissions'] ?? []);

        return redirect()
            ->route('organization.roles.show', $role)
            ->with('success', 'Role created successfully.');
    }

    public function update(Request $request, Role $role)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $role->company_id === $companyId, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:100'],
        ]);

        foreach (($data['permissions'] ?? []) as $permissionName) {
            Permission::findOrCreate($permissionName, 'web');
        }

        $role->update([
            'name' => $data['name'],
        ]);

        $role->syncPermissions($data['permissions'] ?? []);

        return redirect()
            ->route('organization.roles')
            ->with('success', 'Role updated successfully.');
    }

    public function destroy(Role $role)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $role->company_id === $companyId, 404);

        $role->delete();

        return redirect()
            ->route('organization.roles')
            ->with('success', 'Role deleted successfully.');
    }

    public function export(Request $request)
    {
        $format = strtolower((string) $request->query('format', 'csv'));

        $search = trim((string) $request->query('search', ''));
        $companyId = (int) $request->attributes->get('current_company_id');

        $query = Role::query()
            ->where('company_id', $companyId)
            ->latest('id');

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
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
