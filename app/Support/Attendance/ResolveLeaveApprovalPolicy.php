<?php

namespace App\Support\Attendance;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveApprovalPolicy;
use App\Support\Attendance\Data\EffectiveLeaveApprovalPolicy;
use Illuminate\Support\Collection;

final class ResolveLeaveApprovalPolicy
{
    public function forEmployee(Employee $employee, int $companyId): ?EffectiveLeaveApprovalPolicy
    {
        if ((int) $employee->company_id !== $companyId) {
            return null;
        }

        if ($employee->department_id === null) {
            return $this->companyDefault($companyId);
        }

        return $this->forDepartment($companyId, (int) $employee->department_id);
    }

    public function forDepartment(int $companyId, int $departmentId): ?EffectiveLeaveApprovalPolicy
    {
        $departmentsById = $this->departmentsByIdForCompany($companyId);
        $department = $departmentsById->get($departmentId);

        if ($department === null) {
            return $this->companyDefault($companyId);
        }

        $direct = $this->resolveAssignedPolicy($companyId, $department);

        if ($direct !== null) {
            return new EffectiveLeaveApprovalPolicy(
                policy: $direct,
                source: EffectiveLeaveApprovalPolicy::SOURCE_DIRECT,
                sourceDepartment: $department,
            );
        }

        $visited = [$departmentId => true];
        $currentId = $department->parent_id !== null ? (int) $department->parent_id : null;

        while ($currentId !== null && ! isset($visited[$currentId])) {
            $visited[$currentId] = true;
            $ancestor = $departmentsById->get($currentId);

            if ($ancestor === null) {
                break;
            }

            $inherited = $this->resolveAssignedPolicy($companyId, $ancestor);

            if ($inherited !== null) {
                return new EffectiveLeaveApprovalPolicy(
                    policy: $inherited,
                    source: EffectiveLeaveApprovalPolicy::SOURCE_INHERITED,
                    sourceDepartment: $ancestor,
                );
            }

            $currentId = $ancestor->parent_id !== null ? (int) $ancestor->parent_id : null;
        }

        return $this->companyDefault($companyId);
    }

    public function companyDefault(int $companyId): ?EffectiveLeaveApprovalPolicy
    {
        $policy = LeaveApprovalPolicy::query()
            ->where('company_id', $companyId)
            ->where('is_default', true)
            ->where('status', 'active')
            ->with(['steps' => fn ($query) => $query->orderBy('sequence')])
            ->first();

        if ($policy === null) {
            return null;
        }

        return new EffectiveLeaveApprovalPolicy(
            policy: $policy,
            source: EffectiveLeaveApprovalPolicy::SOURCE_COMPANY_DEFAULT,
            sourceDepartment: null,
        );
    }

    private function resolveAssignedPolicy(int $companyId, Department $department): ?LeaveApprovalPolicy
    {
        if ($department->leave_approval_policy_id === null) {
            return null;
        }

        $policy = $department->relationLoaded('leaveApprovalPolicy')
            ? $department->leaveApprovalPolicy
            : null;

        if (
            $policy === null
            || (int) $policy->id !== (int) $department->leave_approval_policy_id
            || (int) $policy->company_id !== $companyId
        ) {
            $policy = LeaveApprovalPolicy::query()
                ->where('company_id', $companyId)
                ->whereKey((int) $department->leave_approval_policy_id)
                ->where('status', 'active')
                ->with(['steps' => fn ($query) => $query->orderBy('sequence')])
                ->first();
        } elseif ($policy->status !== 'active' || (int) $policy->company_id !== $companyId) {
            return null;
        } elseif (! $policy->relationLoaded('steps')) {
            $policy->load(['steps' => fn ($query) => $query->orderBy('sequence')]);
        }

        return $policy;
    }

    /**
     * @return Collection<int, Department>
     */
    private function departmentsByIdForCompany(int $companyId): Collection
    {
        return Department::query()
            ->where('company_id', $companyId)
            ->with([
                'leaveApprovalPolicy' => fn ($query) => $query
                    ->where('company_id', $companyId)
                    ->where('status', 'active'),
                'leaveApprovalPolicy.steps' => fn ($query) => $query->orderBy('sequence'),
            ])
            ->get(['id', 'company_id', 'parent_id', 'leave_approval_policy_id', 'name', 'code', 'status'])
            ->keyBy('id');
    }
}
