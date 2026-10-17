<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Support\Employees\EmployeeVisibilityScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractEmployeeSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        abort_if($companyId <= 0, 403);

        $search = trim((string) $request->query('q', ''));

        if ($search === '') {
            return response()->json([]);
        }

        $like = '%'.addcslashes($search, '%_\\').'%';

        $employees = EmployeeVisibilityScope::apply(
            Employee::query()->where('status', 'active'),
            $request->user(),
            $companyId,
        )
            ->where(function ($query) use ($like): void {
                $query->where('name', 'like', $like)
                    ->orWhere('employee_no', 'like', $like);
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit(20)
            ->get(['id', 'name', 'employee_no'])
            ->map(fn (Employee $employee): array => [
                'id' => (int) $employee->id,
                'name' => (string) $employee->name,
                'employee_no' => $employee->employee_no !== null && $employee->employee_no !== ''
                    ? (string) $employee->employee_no
                    : null,
            ])
            ->values()
            ->all();

        return response()->json($employees);
    }
}
