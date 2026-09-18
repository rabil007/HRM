<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\Employees\EmployeePagePermissions;
use App\Support\Employees\EmployeeTrashDirectoryQuery;
use App\Support\Employees\EmployeeVisibilityScope;
use App\Support\Employees\Resources\EmployeeDeletedListResource;
use App\Support\Pagination\ResolvesPerPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class EmployeeTrashController extends Controller
{
    use ResolvesPerPage;

    public function index(Request $request): InertiaResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $perPage = $this->resolvePerPage($request);
        $search = trim((string) $request->query('search', ''));

        $paginator = EmployeeTrashDirectoryQuery::for($companyId, $search, $request->user())
            ->paginate($perPage)
            ->withQueryString();

        $employees = $paginator->through(
            fn (Employee $employee) => EmployeeDeletedListResource::toArray($employee),
        );

        return Inertia::render('organization/employees-deleted', [
            'employees' => $employees->items(),
            'pagination' => $this->paginationMeta($paginator),
            'search' => $search,
            'can' => EmployeePagePermissions::for($request->user()),
        ]);
    }

    public function restore(Request $request, int $employeeId): RedirectResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');

        $employee = Employee::onlyTrashed()
            ->where('company_id', $companyId)
            ->findOrFail($employeeId);

        abort_unless(EmployeeVisibilityScope::canAccess($request->user(), $employee, $companyId), 404);

        $employeeNo = $employee->employee_no;
        $employee->restore();

        return redirect()
            ->route('organization.employees.deleted', $request->only(['search', 'page', 'per_page']))
            ->with('success', "Employee No. {$employeeNo} has been restored and returned to the Employees directory.");
    }
}
