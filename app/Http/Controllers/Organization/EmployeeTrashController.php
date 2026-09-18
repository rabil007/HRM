<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\Employees\EmployeePagePermissions;
use App\Support\Employees\EmployeeTrashDirectoryQuery;
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

        $paginator = EmployeeTrashDirectoryQuery::for($companyId, $search)
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

        $employeeNo = $employee->employee_no;
        $employee->restore();

        $search = trim((string) $request->query('search', ''));
        $perPage = $this->resolvePerPage($request);
        $page = max(1, (int) $request->query('page', 1));

        $remainingCount = EmployeeTrashDirectoryQuery::for($companyId, $search)->count();
        $lastPage = max(1, (int) ceil($remainingCount / $perPage));

        if ($page > $lastPage) {
            $page = $lastPage;
        }

        $redirectParams = array_filter([
            'search' => $search !== '' ? $search : null,
            'page' => $page > 1 ? $page : null,
            'per_page' => $perPage !== 20 ? $perPage : null,
        ], fn ($value) => $value !== null && $value !== '');

        return redirect()
            ->route('organization.employees.deleted', $redirectParams)
            ->with('success', "Employee No. {$employeeNo} restored successfully. The employee retains their previous status.");
    }
}
