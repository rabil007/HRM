<?php

namespace App\Http\Controllers\Organization\BulkDocuments;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BulkDocumentEmployeeSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $companyId = (int) $request->attributes->get('current_company_id');
        $search = trim((string) $request->query('q', ''));

        if ($search === '') {
            return response()->json(['employees' => []]);
        }

        $like = '%'.addcslashes($search, '%_\\').'%';

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->where(function ($query) use ($like): void {
                $query->where('name', 'like', $like)
                    ->orWhere('employee_no', 'like', $like);
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'employee_no', 'work_email', 'personal_email'])
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => filled($employee->work_email)
                    ? (string) $employee->work_email
                    : (filled($employee->personal_email) ? (string) $employee->personal_email : null),
            ])
            ->filter(fn (array $employee): bool => filled($employee['email']))
            ->values()
            ->all();

        return response()->json(['employees' => $employees]);
    }
}
