<?php

namespace App\Support\Attendance;

use App\Models\Employee;

final class PresentLeaveApproverOption
{
    public function __construct(
        private LeaveApproverEligibility $eligibility,
    ) {}

    /**
     * @param  list<int>|null  $includeEmployeeIds  Always include these employees (e.g. selected inactive)
     * @return list<array{
     *     id: int,
     *     employee_no: string|null,
     *     name: string|null,
     *     employee_status: string|null,
     *     has_linked_user: bool,
     *     linked_user_active: bool,
     *     has_active_company_membership: bool,
     *     has_view_permission: bool,
     *     has_leave_request_approve_permission: bool,
     *     actionable: bool,
     *     warnings: list<string>,
     * }>
     */
    public function forCompany(int $companyId, bool $activeOnly = true, ?array $includeEmployeeIds = null): array
    {
        $includeEmployeeIds = collect($includeEmployeeIds ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $employees = Employee::query()
            ->where('company_id', $companyId)
            ->when($activeOnly && $includeEmployeeIds === [], fn ($query) => $query->where('status', 'active'))
            ->when($activeOnly && $includeEmployeeIds !== [], function ($query) use ($includeEmployeeIds): void {
                $query->where(function ($inner) use ($includeEmployeeIds): void {
                    $inner->where('status', 'active')
                        ->orWhereIn('id', $includeEmployeeIds);
                });
            })
            ->with('user:id,name,email,status')
            ->orderBy('name')
            ->get(['id', 'employee_no', 'name', 'status', 'user_id']);

        return $this->presentMany($employees, $companyId);
    }

    /**
     * @param  iterable<int, Employee>  $employees
     * @return list<array{
     *     id: int,
     *     employee_no: string|null,
     *     name: string|null,
     *     employee_status: string|null,
     *     has_linked_user: bool,
     *     linked_user_active: bool,
     *     has_active_company_membership: bool,
     *     has_view_permission: bool,
     *     has_leave_request_approve_permission: bool,
     *     actionable: bool,
     *     warnings: list<string>,
     * }>
     */
    public function presentMany(iterable $employees, int $companyId): array
    {
        $collection = collect($employees);
        $evaluations = $this->eligibility->evaluateMany($collection, $companyId);

        return $collection
            ->map(fn (Employee $employee) => $this->mapEmployee($employee, $evaluations[(int) $employee->id]))
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     id: int,
     *     employee_no: string|null,
     *     name: string|null,
     *     employee_status: string|null,
     *     has_linked_user: bool,
     *     linked_user_active: bool,
     *     has_active_company_membership: bool,
     *     has_view_permission: bool,
     *     has_leave_request_approve_permission: bool,
     *     actionable: bool,
     *     warnings: list<string>,
     * }
     */
    public function present(Employee $employee, int $companyId): array
    {
        $evaluation = $this->eligibility->evaluate($employee, $companyId);

        return $this->mapEmployee($employee, $evaluation);
    }

    /**
     * @param  array{
     *     employee_active: bool,
     *     has_linked_user: bool,
     *     linked_user_active: bool,
     *     has_active_company_membership: bool,
     *     has_view_permission: bool,
     *     has_approve_permission: bool,
     *     actionable: bool,
     *     warnings: list<string>,
     * }  $evaluation
     * @return array{
     *     id: int,
     *     employee_no: string|null,
     *     name: string|null,
     *     employee_status: string|null,
     *     has_linked_user: bool,
     *     linked_user_active: bool,
     *     has_active_company_membership: bool,
     *     has_view_permission: bool,
     *     has_leave_request_approve_permission: bool,
     *     actionable: bool,
     *     warnings: list<string>,
     * }
     */
    private function mapEmployee(Employee $employee, array $evaluation): array
    {
        return [
            'id' => (int) $employee->id,
            'employee_no' => $employee->employee_no,
            'name' => $employee->name,
            'employee_status' => $employee->status,
            'has_linked_user' => $evaluation['has_linked_user'],
            'linked_user_active' => $evaluation['linked_user_active'],
            'has_active_company_membership' => $evaluation['has_active_company_membership'],
            'has_view_permission' => $evaluation['has_view_permission'],
            'has_leave_request_approve_permission' => $evaluation['has_approve_permission'],
            'actionable' => $evaluation['actionable'],
            'warnings' => $evaluation['warnings'],
        ];
    }
}
