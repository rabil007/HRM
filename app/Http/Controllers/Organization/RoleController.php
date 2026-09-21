<?php

namespace App\Http\Controllers\Organization;

use App\Exports\RolesExport;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Authorization\ApplicationPermissionRegistry;
use App\Support\Authorization\Presenters\PermissionOptionPresenter;
use App\Support\Departments\BuildDepartmentTree;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Pagination\ResolvesPerPage;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;

class RoleController extends Controller
{
    use ResolvesPerPage;

    public function index()
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage(request());
        $search = trim((string) request()->query('search', ''));
        $hasPermissions = trim((string) request()->query('has_permissions', ''));

        $paginator = Role::query()
            ->where('company_id', $companyId)
            ->with('permissions:id,name')
            ->when($search, fn ($q) => $q->where('name', 'like', "%{$search}%"))
            ->when($hasPermissions === 'true', fn ($q) => $q->has('permissions'))
            ->when($hasPermissions === 'false', fn ($q) => $q->doesntHave('permissions'))
            ->latest('id')
            ->paginate($perPage)
            ->withQueryString();

        $roles = $paginator->through(fn (Role $role) => [
            'id' => $role->id,
            'name' => $role->name,
            'employee_visibility_scope' => $role->employee_visibility_scope ?? Role::SCOPE_ALL,
            'permissions' => $role->permissions->pluck('name')->all(),
            'created_at' => $role->created_at,
        ]);

        $company = Company::query()->whereKey($companyId)->first(['id', 'name']);

        return Inertia::render('organization/roles', [
            'roles' => $roles->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'filters' => [
                'has_permissions' => $hasPermissions,
            ],
            'company' => $company,
        ]);
    }

    public function show(Role $role)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $role->company_id === $companyId, 404);

        $permissions = self::applicationPermissionOptions();
        $company = Company::query()->whereKey($companyId)->first(['id', 'name', 'slug']);
        $departmentTree = BuildDepartmentTree::forCompany($companyId);
        $departmentIds = $role->employeeVisibilityDepartments()
            ->where('departments.company_id', $companyId)
            ->pluck('departments.id')
            ->map(intval(...))
            ->all();

        $registryPermissionNames = ApplicationPermissionRegistry::names();

        return Inertia::render('organization/role', [
            'role' => [
                'id' => $role->id,
                'name' => $role->name,
                'employee_visibility_scope' => $role->name === 'Owner'
                    ? Role::SCOPE_ALL
                    : ($role->employee_visibility_scope ?? Role::SCOPE_ALL),
                'department_ids' => $role->name === 'Owner' ? [] : $departmentIds,
                'permissions' => $role->permissions()
                    ->pluck('name')
                    ->filter(fn (string $name) => in_array($name, $registryPermissionNames, true))
                    ->values()
                    ->all(),
                'created_at' => $role->created_at,
                'updated_at' => $role->updated_at,
            ],
            'company' => $company,
            'permissions' => $permissions,
            'department_tree' => $departmentTree,
        ]);
    }

    public function store(Request $request)
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $data = $request->validate(self::roleValidationRules($companyId));

        $role = DB::transaction(function () use ($companyId, $data) {
            $scope = $data['employee_visibility_scope'] ?? Role::SCOPE_ALL;

            $role = Role::query()->create([
                'company_id' => $companyId,
                'name' => $data['name'],
                'guard_name' => 'web',
                'employee_visibility_scope' => $scope,
            ]);

            $role->syncPermissions($data['permissions'] ?? []);

            if ($scope === Role::SCOPE_SELECTED_DEPARTMENTS && ! empty($data['department_ids'])) {
                $syncData = [];
                foreach ($data['department_ids'] as $departmentId) {
                    $syncData[(int) $departmentId] = ['company_id' => $companyId];
                }
                $role->employeeVisibilityDepartments()->sync($syncData);
            }

            EmployeeVisibilityScope::clearCache();

            return $role;
        });

        return redirect()
            ->route('organization.roles.show', $role)
            ->with('success', 'Role created successfully.');
    }

    public function update(Request $request, Role $role)
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_unless((int) $role->company_id === $companyId, 404);

        $data = $request->validate(self::roleValidationRules($companyId));

        if ($role->name === 'Owner') {
            if ($data['name'] !== 'Owner' || $request->exists('permissions')) {
                throw ValidationException::withMessages([
                    'name' => 'The Owner role cannot be renamed or have its permissions modified.',
                ]);
            }

            if (($data['employee_visibility_scope'] ?? Role::SCOPE_ALL) !== Role::SCOPE_ALL
                || ! empty($data['department_ids'])) {
                throw ValidationException::withMessages([
                    'employee_visibility_scope' => 'The Owner role employee visibility cannot be restricted.',
                ]);
            }

            return redirect()
                ->route('organization.roles')
                ->with('success', 'Role updated successfully.');
        }

        DB::transaction(function () use ($role, $companyId, $data, $request) {
            $scope = $request->has('employee_visibility_scope')
                ? ($data['employee_visibility_scope'] ?? Role::SCOPE_ALL)
                : ($role->employee_visibility_scope ?? Role::SCOPE_ALL);

            $role->update([
                'name' => $data['name'],
                'employee_visibility_scope' => $scope,
            ]);

            if ($request->exists('permissions')) {
                $role->syncPermissions($data['permissions'] ?? []);
            }

            if ($scope === Role::SCOPE_SELECTED_DEPARTMENTS && $request->has('department_ids')) {
                $syncData = [];
                foreach ($data['department_ids'] ?? [] as $departmentId) {
                    $syncData[(int) $departmentId] = ['company_id' => $companyId];
                }
                $role->employeeVisibilityDepartments()->sync($syncData);
            } elseif ($request->has('employee_visibility_scope') && $scope === Role::SCOPE_ALL) {
                $role->employeeVisibilityDepartments()->detach();
            }

            EmployeeVisibilityScope::clearCache();
        });

        return redirect()
            ->route('organization.roles')
            ->with('success', 'Role updated successfully.');
    }

    public function destroy(Role $role)
    {
        $companyId = (int) request()->attributes->get('current_company_id');
        abort_unless((int) $role->company_id === $companyId, 404);

        if ($role->name === 'Owner') {
            throw ValidationException::withMessages([
                'name' => 'The Owner role cannot be deleted.',
            ]);
        }

        DB::transaction(function () use ($role) {
            $role->employeeVisibilityDepartments()->detach();
            $role->delete();
            EmployeeVisibilityScope::clearCache();
        });

        return redirect()
            ->route('organization.roles')
            ->with('success', 'Role deleted successfully.');
    }

    /**
     * @return array<string, list<mixed>>
     */
    private static function roleValidationRules(int $companyId): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'employee_visibility_scope' => [
                'sometimes',
                'string',
                Rule::in([Role::SCOPE_ALL, Role::SCOPE_SELECTED_DEPARTMENTS]),
            ],
            'department_ids' => [
                'nullable',
                'array',
                'required_if:employee_visibility_scope,'.Role::SCOPE_SELECTED_DEPARTMENTS,
                Rule::prohibitedIf(fn () => request()->input('employee_visibility_scope') === Role::SCOPE_ALL),
            ],
            'department_ids.*' => [
                'integer',
                Rule::exists('departments', 'id')->where(function ($query) use ($companyId): void {
                    $query->where('company_id', $companyId)
                        ->where('status', 'active');
                }),
            ],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:100', Rule::in(ApplicationPermissionRegistry::names())],
        ];
    }

    /**
     * @return list<array{id: int, name: string, label: string, description: string|null, group: string}>
     */
    private static function applicationPermissionOptions(): array
    {
        return PermissionOptionPresenter::collection(
            Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', ApplicationPermissionRegistry::names())
                ->orderBy('name')
                ->get(['id', 'name', 'label', 'description']),
        );
    }

    public function export(Request $request)
    {
        $format = strtolower((string) $request->query('format', 'csv'));

        $search = trim((string) $request->query('search', ''));
        $companyId = (int) $request->attributes->get('current_company_id');

        $query = Role::query()
            ->where('company_id', $companyId)
            ->with('permissions:id,name')
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
