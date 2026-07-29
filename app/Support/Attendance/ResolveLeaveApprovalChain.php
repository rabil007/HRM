<?php

namespace App\Support\Attendance;

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveRequestApprovalStatus;
use App\Models\CompanyLeaveApprovalSetting;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveApprovalPolicyStep;
use App\Models\LeaveRequest;
use App\Models\LeaveRequestApproval;
use App\Models\User;
use App\Support\Attendance\Data\EffectiveLeaveApprovalPolicy;
use App\Support\Attendance\Data\ResolvedLeaveApprovalChain;
use App\Support\Attendance\Data\ResolvedLeaveApprovalStep;
use App\Support\Departments\ResolveDepartmentManagementChain;
use RuntimeException;

final class ResolveLeaveApprovalChain
{
    public function __construct(
        private ResolveLeaveApprovalPolicy $resolvePolicy,
        private LeaveApproverEligibility $eligibility,
    ) {}

    public function handle(Employee $employee, int $companyId): ResolvedLeaveApprovalChain
    {
        if ((int) $employee->company_id !== $companyId) {
            throw new RuntimeException('Employee does not belong to the active company.');
        }

        $effective = $this->resolvePolicy->forEmployee($employee, $companyId);

        if ($effective === null) {
            throw new RuntimeException(
                'No leave approval policy is configured for this employee. Assign a department policy or set a company default.',
            );
        }

        return $this->resolveFromEffectivePolicy($employee, $companyId, $effective);
    }

    public function resolveFromEffectivePolicy(
        Employee $employee,
        int $companyId,
        EffectiveLeaveApprovalPolicy $effective,
    ): ResolvedLeaveApprovalChain {
        $policy = $effective->policy;
        $policy->loadMissing(['steps' => fn ($query) => $query->orderBy('sequence')]);

        if ($policy->steps->isEmpty()) {
            throw new RuntimeException(sprintf(
                'Leave approval policy "%s" has no steps configured.',
                $policy->name,
            ));
        }

        $settings = CompanyLeaveApprovalSetting::findForCompany($companyId);
        $managementChain = ResolveDepartmentManagementChain::forEmployee($employee);
        $requesterId = (int) $employee->id;
        $usedManagerEmployeeIds = [];
        $resolved = [];

        foreach ($policy->steps as $step) {
            /** @var LeaveApprovalPolicyStep $step */
            $candidate = $this->resolveStep(
                step: $step,
                companyId: $companyId,
                requesterEmployeeId: $requesterId,
                managementChain: $managementChain,
                usedManagerEmployeeIds: $usedManagerEmployeeIds,
                settings: $settings,
            );

            if ($candidate === null) {
                if ($step->is_required) {
                    throw new RuntimeException($this->missingStepMessage($step));
                }

                continue;
            }

            if ((int) $candidate['employee']->id === $requesterId) {
                if ($step->is_required) {
                    throw new RuntimeException(sprintf(
                        'Required approval step "%s" resolved to the requesting employee, which is not allowed.',
                        $step->approver_type->label(),
                    ));
                }

                continue;
            }

            if (! $this->isActionable($candidate['employee'], $companyId, $requesterId)) {
                if ($step->is_required) {
                    throw new RuntimeException(sprintf(
                        'Required approval step "%s" resolved to an employee who is not an actionable approver (active employee, linked active user, active company membership, and leave-request view and approve permissions).',
                        $step->approver_type->label(),
                    ));
                }

                continue;
            }

            $employeeId = (int) $candidate['employee']->id;

            if ($this->isManagerChainType($step->approver_type)) {
                $usedManagerEmployeeIds[$employeeId] = true;
            }

            $resolved[] = [
                'step' => $step,
                'employee' => $candidate['employee'],
                'user' => $candidate['employee']->user,
                'source_department' => $candidate['source_department'],
            ];
        }

        $deduped = $this->dedupeByEmployee($resolved);

        if ($deduped === []) {
            throw new RuntimeException(
                'Leave approval chain is empty after resolving policy steps. Configure actionable approvers for the effective policy.',
            );
        }

        $requiredRemain = array_filter(
            $deduped,
            fn (array $entry): bool => (bool) $entry['step']->is_required,
        );

        if ($requiredRemain === []) {
            throw new RuntimeException(
                'Leave approval chain has no required actionable steps after resolution.',
            );
        }

        $steps = [];
        $sequence = 1;

        foreach ($deduped as $entry) {
            $steps[] = new ResolvedLeaveApprovalStep(
                sequence: $sequence,
                approverType: $entry['step']->approver_type,
                approverEmployee: $entry['employee'],
                approverUser: $entry['user'],
                isRequired: (bool) $entry['step']->is_required,
                sourceDepartment: $entry['source_department'],
                policyStep: $entry['step'],
            );
            $sequence++;
        }

        return new ResolvedLeaveApprovalChain(
            policy: $policy,
            effectivePolicy: $effective,
            steps: $steps,
        );
    }

    /**
     * Persist a resolved approval snapshot onto a leave request.
     * Optional steps before the first required step are skipped immediately;
     * the first required step becomes pending; later steps wait.
     *
     * @return list<LeaveRequestApproval>
     */
    public function persistSnapshot(LeaveRequest $leaveRequest, ResolvedLeaveApprovalChain $chain): array
    {
        $companyId = (int) $leaveRequest->company_id;
        $created = [];
        $pendingAssigned = false;
        $actedAt = now();

        foreach ($chain->steps as $resolvedStep) {
            if (! $pendingAssigned && ! $resolvedStep->isRequired) {
                $created[] = LeaveRequestApproval::query()->create([
                    ...$resolvedStep->toPersistenceArray(
                        companyId: $companyId,
                        leaveRequestId: (int) $leaveRequest->id,
                        policyId: (int) $chain->policy->id,
                        policyName: (string) $chain->policy->name,
                    ),
                    'status' => LeaveRequestApprovalStatus::Skipped,
                    'acted_at' => $actedAt,
                    'comments' => null,
                ]);

                continue;
            }

            $status = LeaveRequestApprovalStatus::Waiting;
            $stepActedAt = null;

            if (! $pendingAssigned && $resolvedStep->isRequired) {
                $status = LeaveRequestApprovalStatus::Pending;
                $pendingAssigned = true;
            }

            $created[] = LeaveRequestApproval::query()->create([
                ...$resolvedStep->toPersistenceArray(
                    companyId: $companyId,
                    leaveRequestId: (int) $leaveRequest->id,
                    policyId: (int) $chain->policy->id,
                    policyName: (string) $chain->policy->name,
                ),
                'status' => $status,
                'acted_at' => $stepActedAt,
                'comments' => null,
            ]);
        }

        if (! $pendingAssigned) {
            throw new RuntimeException('Unable to persist leave approval snapshot without a pending required step.');
        }

        return $created;
    }

    /**
     * @param  list<array{manager: Employee, manager_user: User|null, source_department: Department, is_direct: bool, hierarchy_order: int}>  $managementChain
     * @param  array<int, true>  $usedManagerEmployeeIds
     * @return array{employee: Employee, source_department: Department|null}|null
     */
    private function resolveStep(
        LeaveApprovalPolicyStep $step,
        int $companyId,
        int $requesterEmployeeId,
        array $managementChain,
        array &$usedManagerEmployeeIds,
        CompanyLeaveApprovalSetting $settings,
    ): ?array {
        return match ($step->approver_type) {
            LeaveApprovalApproverType::DepartmentManager => $this->resolveDepartmentManager(
                companyId: $companyId,
                requesterEmployeeId: $requesterEmployeeId,
                managementChain: $managementChain,
                usedManagerEmployeeIds: $usedManagerEmployeeIds,
                settings: $settings,
            ),
            LeaveApprovalApproverType::ParentManager => $this->resolveParentManager(
                companyId: $companyId,
                requesterEmployeeId: $requesterEmployeeId,
                managementChain: $managementChain,
                usedManagerEmployeeIds: $usedManagerEmployeeIds,
            ),
            LeaveApprovalApproverType::HrApprover => $this->resolveHrApprover(
                step: $step,
                companyId: $companyId,
                settings: $settings,
            ),
            LeaveApprovalApproverType::SpecificEmployee => $this->resolveSpecificEmployee(
                step: $step,
                companyId: $companyId,
            ),
        };
    }

    /**
     * @param  list<array{manager: Employee, manager_user: User|null, source_department: Department, is_direct: bool, hierarchy_order: int}>  $managementChain
     * @param  array<int, true>  $usedManagerEmployeeIds
     * @return array{employee: Employee, source_department: Department|null}|null
     */
    private function resolveDepartmentManager(
        int $companyId,
        int $requesterEmployeeId,
        array $managementChain,
        array $usedManagerEmployeeIds,
        CompanyLeaveApprovalSetting $settings,
    ): ?array {
        foreach ($managementChain as $entry) {
            $manager = $entry['manager'];
            $managerId = (int) $manager->id;

            if (isset($usedManagerEmployeeIds[$managerId])) {
                continue;
            }

            if ($managerId === $requesterEmployeeId) {
                continue;
            }

            if (! $this->isActionable($manager, $companyId, $requesterEmployeeId)) {
                continue;
            }

            return [
                'employee' => $manager,
                'source_department' => $entry['source_department'],
            ];
        }

        $fallbackId = $settings->fallback_approver_employee_id;

        if ($fallbackId === null) {
            return null;
        }

        $fallback = $this->loadCompanyEmployee($companyId, (int) $fallbackId);

        if ($fallback === null || (int) $fallback->id === $requesterEmployeeId) {
            return null;
        }

        return [
            'employee' => $fallback,
            'source_department' => null,
        ];
    }

    /**
     * @param  list<array{manager: Employee, manager_user: User|null, source_department: Department, is_direct: bool, hierarchy_order: int}>  $managementChain
     * @param  array<int, true>  $usedManagerEmployeeIds
     * @return array{employee: Employee, source_department: Department|null}|null
     */
    private function resolveParentManager(
        int $companyId,
        int $requesterEmployeeId,
        array $managementChain,
        array $usedManagerEmployeeIds,
    ): ?array {
        foreach ($managementChain as $entry) {
            $manager = $entry['manager'];
            $managerId = (int) $manager->id;

            if (isset($usedManagerEmployeeIds[$managerId])) {
                continue;
            }

            if ($managerId === $requesterEmployeeId) {
                continue;
            }

            if (! $this->isActionable($manager, $companyId, $requesterEmployeeId)) {
                continue;
            }

            return [
                'employee' => $manager,
                'source_department' => $entry['source_department'],
            ];
        }

        return null;
    }

    /**
     * @return array{employee: Employee, source_department: null}|null
     */
    private function resolveHrApprover(
        LeaveApprovalPolicyStep $step,
        int $companyId,
        CompanyLeaveApprovalSetting $settings,
    ): ?array {
        $employeeId = $step->approver_employee_id ?? $settings->default_hr_approver_employee_id;

        if ($employeeId === null) {
            return null;
        }

        $employee = $this->loadCompanyEmployee($companyId, (int) $employeeId);

        if ($employee === null) {
            return null;
        }

        return [
            'employee' => $employee,
            'source_department' => null,
        ];
    }

    /**
     * @return array{employee: Employee, source_department: null}|null
     */
    private function resolveSpecificEmployee(LeaveApprovalPolicyStep $step, int $companyId): ?array
    {
        if ($step->approver_employee_id === null) {
            return null;
        }

        $employee = $this->loadCompanyEmployee($companyId, (int) $step->approver_employee_id);

        if ($employee === null) {
            return null;
        }

        return [
            'employee' => $employee,
            'source_department' => null,
        ];
    }

    private function loadCompanyEmployee(int $companyId, int $employeeId): ?Employee
    {
        return Employee::query()
            ->where('company_id', $companyId)
            ->whereKey($employeeId)
            ->with('user:id,name,email,status')
            ->first();
    }

    private function isActionable(Employee $employee, int $companyId, int $requesterEmployeeId): bool
    {
        if ((int) $employee->company_id !== $companyId) {
            return false;
        }

        if ((int) $employee->id === $requesterEmployeeId) {
            return false;
        }

        return $this->eligibility->evaluate($employee, $companyId)['actionable'];
    }

    private function isManagerChainType(LeaveApprovalApproverType $type): bool
    {
        return $type === LeaveApprovalApproverType::DepartmentManager
            || $type === LeaveApprovalApproverType::ParentManager;
    }

    /**
     * @param  list<array{step: LeaveApprovalPolicyStep, employee: Employee, user: User|null, source_department: Department|null}>  $resolved
     * @return list<array{step: LeaveApprovalPolicyStep, employee: Employee, user: User, source_department: Department|null}>
     */
    private function dedupeByEmployee(array $resolved): array
    {
        $seen = [];
        $deduped = [];

        foreach ($resolved as $entry) {
            $employeeId = (int) $entry['employee']->id;

            if (isset($seen[$employeeId])) {
                continue;
            }

            if ($entry['user'] === null) {
                continue;
            }

            $seen[$employeeId] = true;
            $deduped[] = $entry;
        }

        return $deduped;
    }

    private function missingStepMessage(LeaveApprovalPolicyStep $step): string
    {
        return match ($step->approver_type) {
            LeaveApprovalApproverType::DepartmentManager => 'Required department-manager approval step could not be resolved. Configure a department manager or company fallback approver.',
            LeaveApprovalApproverType::ParentManager => 'Required parent-manager approval step could not be resolved. Ensure a distinct parent-level manager exists in the department hierarchy.',
            LeaveApprovalApproverType::HrApprover => 'Required HR approver step could not be resolved. Select an HR employee on the policy step or configure the company default HR approver.',
            LeaveApprovalApproverType::SpecificEmployee => 'Required specific-employee approval step could not be resolved. Select an actionable employee on the policy step.',
        };
    }
}
