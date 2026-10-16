<?php

namespace App\Support\Attendance;

use App\Enums\LeaveApprovalApproverType;
use App\Enums\LeaveApprovalMode;
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
    public const SELF_APPROVAL_ALTERNATIVE_REQUIRED_MESSAGE = 'A different approver is required for this leave request. Please configure an eligible alternative approver in Leave Approval Settings.';

    public function __construct(
        private ResolveLeaveApprovalPolicy $resolvePolicy,
        private LeaveApproverEligibility $eligibility,
        private LeaveRequestVisibility $visibility,
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

        $employee->loadMissing('user:id,name,email,status');

        $settings = CompanyLeaveApprovalSetting::findForCompany($companyId);
        $managementChain = ResolveDepartmentManagementChain::forEmployee($employee);
        $requesterId = (int) $employee->id;
        $requesterUserId = $employee->user_id !== null ? (int) $employee->user_id : null;
        $reservedNaturalManagerEmployeeIds = $this->reserveNaturalManagerEmployeeIds(
            steps: $policy->steps,
            companyId: $companyId,
            requester: $employee,
            requesterEmployeeId: $requesterId,
            requesterUserId: $requesterUserId,
            managementChain: $managementChain,
        );
        $usedManagerEmployeeIds = [];
        $usedRequiredApproverEmployeeIds = [];
        $usedRequiredApproverUserIds = [];
        $resolved = [];

        foreach ($policy->steps as $step) {
            /** @var LeaveApprovalPolicyStep $step */
            $candidate = $this->resolveStep(
                step: $step,
                companyId: $companyId,
                requester: $employee,
                requesterEmployeeId: $requesterId,
                requesterUserId: $requesterUserId,
                managementChain: $managementChain,
                usedManagerEmployeeIds: $usedManagerEmployeeIds,
                usedRequiredApproverEmployeeIds: $usedRequiredApproverEmployeeIds,
                usedRequiredApproverUserIds: $usedRequiredApproverUserIds,
                settings: $settings,
            );

            if ($candidate === null) {
                if ($step->is_required) {
                    throw new RuntimeException($this->missingStepMessage($step));
                }

                continue;
            }

            $sharesRequester = $this->sharesRequesterIdentity(
                $candidate['employee'],
                $requesterId,
                $requesterUserId,
            );

            if ($sharesRequester) {
                if (! $step->is_required) {
                    continue;
                }

                $candidate = $this->resolveDistinctAlternativeApprover(
                    companyId: $companyId,
                    requester: $employee,
                    requesterEmployeeId: $requesterId,
                    requesterUserId: $requesterUserId,
                    managementChain: $managementChain,
                    usedManagerEmployeeIds: $usedManagerEmployeeIds,
                    usedRequiredApproverEmployeeIds: $usedRequiredApproverEmployeeIds,
                    usedRequiredApproverUserIds: $usedRequiredApproverUserIds,
                    reservedNaturalManagerEmployeeIds: $reservedNaturalManagerEmployeeIds,
                    settings: $settings,
                    forStepType: $step->approver_type,
                );

                if ($candidate === null) {
                    throw new RuntimeException(self::SELF_APPROVAL_ALTERNATIVE_REQUIRED_MESSAGE);
                }
            }

            if ($step->is_required) {
                if (! $this->isActionable(
                    $candidate['employee'],
                    $companyId,
                    $employee,
                    $requesterId,
                    $requesterUserId,
                )) {
                    $primaryCandidate = $candidate;

                    // Primary designated approver cannot act on this requester — try a substitute
                    // (e.g. view_all fallback blocked by EmployeeVisibilityScope).
                    $candidate = $this->resolveDistinctAlternativeApprover(
                        companyId: $companyId,
                        requester: $employee,
                        requesterEmployeeId: $requesterId,
                        requesterUserId: $requesterUserId,
                        managementChain: $managementChain,
                        usedManagerEmployeeIds: $usedManagerEmployeeIds,
                        usedRequiredApproverEmployeeIds: $usedRequiredApproverEmployeeIds,
                        usedRequiredApproverUserIds: $usedRequiredApproverUserIds,
                        reservedNaturalManagerEmployeeIds: $reservedNaturalManagerEmployeeIds,
                        settings: $settings,
                        forStepType: $step->approver_type,
                    );

                    if (
                        $candidate === null
                        || ! $this->isActionable(
                            $candidate['employee'],
                            $companyId,
                            $employee,
                            $requesterId,
                            $requesterUserId,
                        )
                    ) {
                        $evaluation = $this->eligibility->evaluate($primaryCandidate['employee'], $companyId);

                        if (! $evaluation['actionable']) {
                            $detail = $evaluation['warnings'][0]
                                ?? 'The resolved employee is not an actionable approver (active employee, linked active user, active company membership, and leave-request view and approve permissions).';

                            throw new RuntimeException(sprintf(
                                'Required approval step "%s" cannot be completed: %s',
                                $step->approver_type->label(),
                                $detail,
                            ));
                        }

                        throw new RuntimeException(self::SELF_APPROVAL_ALTERNATIVE_REQUIRED_MESSAGE);
                    }
                }

                if ($this->isAlreadyUsedRequiredApprover(
                    $candidate['employee'],
                    $usedRequiredApproverEmployeeIds,
                    $usedRequiredApproverUserIds,
                )) {
                    $candidate = $this->resolveDistinctAlternativeApprover(
                        companyId: $companyId,
                        requester: $employee,
                        requesterEmployeeId: $requesterId,
                        requesterUserId: $requesterUserId,
                        managementChain: $managementChain,
                        usedManagerEmployeeIds: $usedManagerEmployeeIds,
                        usedRequiredApproverEmployeeIds: $usedRequiredApproverEmployeeIds,
                        usedRequiredApproverUserIds: $usedRequiredApproverUserIds,
                        reservedNaturalManagerEmployeeIds: $reservedNaturalManagerEmployeeIds,
                        settings: $settings,
                        forStepType: $step->approver_type,
                    );

                    if (
                        $candidate === null
                        || ! $this->isActionable(
                            $candidate['employee'],
                            $companyId,
                            $employee,
                            $requesterId,
                            $requesterUserId,
                        )
                        || $this->isAlreadyUsedRequiredApprover(
                            $candidate['employee'],
                            $usedRequiredApproverEmployeeIds,
                            $usedRequiredApproverUserIds,
                        )
                    ) {
                        throw new RuntimeException(self::SELF_APPROVAL_ALTERNATIVE_REQUIRED_MESSAGE);
                    }
                }
            } elseif (! $this->isNotifiableListener(
                $candidate['employee'],
                $companyId,
                $requesterId,
                $requesterUserId,
            )) {
                continue;
            }

            $employeeId = (int) $candidate['employee']->id;
            $approverUserId = $candidate['employee']->user_id !== null
                ? (int) $candidate['employee']->user_id
                : null;

            if ($this->isManagerChainType($step->approver_type)) {
                $usedManagerEmployeeIds[$employeeId] = true;
            }

            if ($step->is_required) {
                $usedRequiredApproverEmployeeIds[$employeeId] = true;

                if ($approverUserId !== null) {
                    $usedRequiredApproverUserIds[$approverUserId] = true;
                }
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
     *
     * Snapshots the policy's approval mode onto the leave request so later
     * policy edits cannot silently change an in-flight workflow.
     *
     * All required: first required step becomes Pending, later ones Waiting.
     * Any required: every required step becomes Pending immediately.
     * Non-required (notify-only) steps are always Skipped and never actionable.
     *
     * @return list<LeaveRequestApproval>
     */
    public function persistSnapshot(LeaveRequest $leaveRequest, ResolvedLeaveApprovalChain $chain): array
    {
        $companyId = (int) $leaveRequest->company_id;
        $mode = $chain->policy->approvalMode();
        $created = [];
        $pendingAssigned = false;
        $actedAt = now();

        $leaveRequest->forceFill([
            'approval_mode' => $mode,
        ])->save();

        foreach ($chain->steps as $resolvedStep) {
            if (! $resolvedStep->isRequired) {
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

            if ($mode === LeaveApprovalMode::AnyRequired) {
                $status = LeaveRequestApprovalStatus::Pending;
                $pendingAssigned = true;
                $stepActedAt = null;
            } else {
                $status = LeaveRequestApprovalStatus::Waiting;
                $stepActedAt = null;

                if (! $pendingAssigned) {
                    $status = LeaveRequestApprovalStatus::Pending;
                    $pendingAssigned = true;
                }
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
     * @param  array<int, true>  $usedRequiredApproverEmployeeIds
     * @param  array<int, true>  $usedRequiredApproverUserIds
     * @return array{employee: Employee, source_department: Department|null}|null
     */
    private function resolveStep(
        LeaveApprovalPolicyStep $step,
        int $companyId,
        Employee $requester,
        int $requesterEmployeeId,
        ?int $requesterUserId,
        array $managementChain,
        array &$usedManagerEmployeeIds,
        array $usedRequiredApproverEmployeeIds,
        array $usedRequiredApproverUserIds,
        CompanyLeaveApprovalSetting $settings,
    ): ?array {
        return match ($step->approver_type) {
            LeaveApprovalApproverType::DepartmentManager => $this->resolveDepartmentManager(
                companyId: $companyId,
                requester: $requester,
                requesterEmployeeId: $requesterEmployeeId,
                requesterUserId: $requesterUserId,
                managementChain: $managementChain,
                usedManagerEmployeeIds: $usedManagerEmployeeIds,
                usedRequiredApproverEmployeeIds: $usedRequiredApproverEmployeeIds,
                usedRequiredApproverUserIds: $usedRequiredApproverUserIds,
                settings: $settings,
            ),
            LeaveApprovalApproverType::ParentManager => $this->resolveParentManager(
                companyId: $companyId,
                requester: $requester,
                requesterEmployeeId: $requesterEmployeeId,
                requesterUserId: $requesterUserId,
                managementChain: $managementChain,
                usedManagerEmployeeIds: $usedManagerEmployeeIds,
                usedRequiredApproverEmployeeIds: $usedRequiredApproverEmployeeIds,
                usedRequiredApproverUserIds: $usedRequiredApproverUserIds,
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
     * @param  array<int, true>  $usedRequiredApproverEmployeeIds
     * @param  array<int, true>  $usedRequiredApproverUserIds
     * @return array{employee: Employee, source_department: Department|null}|null
     */
    private function resolveDepartmentManager(
        int $companyId,
        Employee $requester,
        int $requesterEmployeeId,
        ?int $requesterUserId,
        array $managementChain,
        array $usedManagerEmployeeIds,
        array $usedRequiredApproverEmployeeIds,
        array $usedRequiredApproverUserIds,
        CompanyLeaveApprovalSetting $settings,
    ): ?array {
        foreach ($managementChain as $entry) {
            $manager = $entry['manager'];
            $managerId = (int) $manager->id;

            if (isset($usedManagerEmployeeIds[$managerId])) {
                continue;
            }

            if ($this->isAlreadyUsedRequiredApprover(
                $manager,
                $usedRequiredApproverEmployeeIds,
                $usedRequiredApproverUserIds,
            )) {
                continue;
            }

            if ($this->sharesRequesterIdentity($manager, $requesterEmployeeId, $requesterUserId)) {
                continue;
            }

            if (! $this->isActionable($manager, $companyId, $requester, $requesterEmployeeId, $requesterUserId)) {
                continue;
            }

            return [
                'employee' => $manager,
                'source_department' => $entry['source_department'],
            ];
        }

        return $this->resolveFallbackApprover(
            companyId: $companyId,
            requester: $requester,
            requesterEmployeeId: $requesterEmployeeId,
            requesterUserId: $requesterUserId,
            usedManagerEmployeeIds: $usedManagerEmployeeIds,
            usedRequiredApproverEmployeeIds: $usedRequiredApproverEmployeeIds,
            usedRequiredApproverUserIds: $usedRequiredApproverUserIds,
            settings: $settings,
            requireActionable: false,
        );
    }

    /**
     * @param  list<array{manager: Employee, manager_user: User|null, source_department: Department, is_direct: bool, hierarchy_order: int}>  $managementChain
     * @param  array<int, true>  $usedManagerEmployeeIds
     * @param  array<int, true>  $usedRequiredApproverEmployeeIds
     * @param  array<int, true>  $usedRequiredApproverUserIds
     * @return array{employee: Employee, source_department: Department|null}|null
     */
    private function resolveParentManager(
        int $companyId,
        Employee $requester,
        int $requesterEmployeeId,
        ?int $requesterUserId,
        array $managementChain,
        array $usedManagerEmployeeIds,
        array $usedRequiredApproverEmployeeIds,
        array $usedRequiredApproverUserIds,
    ): ?array {
        foreach ($managementChain as $entry) {
            $manager = $entry['manager'];
            $managerId = (int) $manager->id;

            if (isset($usedManagerEmployeeIds[$managerId])) {
                continue;
            }

            if ($this->isAlreadyUsedRequiredApprover(
                $manager,
                $usedRequiredApproverEmployeeIds,
                $usedRequiredApproverUserIds,
            )) {
                continue;
            }

            if ($this->sharesRequesterIdentity($manager, $requesterEmployeeId, $requesterUserId)) {
                continue;
            }

            if (! $this->isActionable($manager, $companyId, $requester, $requesterEmployeeId, $requesterUserId)) {
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

    /**
     * Resolve a distinct actionable substitute when a required step points at the requester
     * (or would otherwise reuse a required approver already on the chain).
     *
     * For HR Approver / Specific Employee conflicts, prefer the company fallback first so
     * natural department/parent managers remain available for later manager steps.
     * Manager-chain conflicts keep hierarchy-then-fallback precedence.
     *
     * Never invents approvers from HR/administrator roles alone.
     *
     * @param  list<array{manager: Employee, manager_user: User|null, source_department: Department, is_direct: bool, hierarchy_order: int}>  $managementChain
     * @param  array<int, true>  $usedManagerEmployeeIds
     * @param  array<int, true>  $usedRequiredApproverEmployeeIds
     * @param  array<int, true>  $usedRequiredApproverUserIds
     * @return array{employee: Employee, source_department: Department|null}|null
     */
    private function resolveDistinctAlternativeApprover(
        int $companyId,
        Employee $requester,
        int $requesterEmployeeId,
        ?int $requesterUserId,
        array $managementChain,
        array $usedManagerEmployeeIds,
        array $usedRequiredApproverEmployeeIds,
        array $usedRequiredApproverUserIds,
        array $reservedNaturalManagerEmployeeIds,
        CompanyLeaveApprovalSetting $settings,
        LeaveApprovalApproverType $forStepType,
    ): ?array {
        $preferFallbackFirst = ! $this->isManagerChainType($forStepType);
        $protectNaturalManagers = $preferFallbackFirst;

        if ($preferFallbackFirst) {
            $fallback = $this->resolveFallbackApprover(
                companyId: $companyId,
                requester: $requester,
                requesterEmployeeId: $requesterEmployeeId,
                requesterUserId: $requesterUserId,
                usedManagerEmployeeIds: $usedManagerEmployeeIds,
                usedRequiredApproverEmployeeIds: $usedRequiredApproverEmployeeIds,
                usedRequiredApproverUserIds: $usedRequiredApproverUserIds,
                settings: $settings,
                requireActionable: true,
            );

            if ($fallback !== null) {
                return $fallback;
            }

            return $this->resolveHierarchyAlternative(
                companyId: $companyId,
                requester: $requester,
                requesterEmployeeId: $requesterEmployeeId,
                requesterUserId: $requesterUserId,
                managementChain: $managementChain,
                usedManagerEmployeeIds: $usedManagerEmployeeIds,
                usedRequiredApproverEmployeeIds: $usedRequiredApproverEmployeeIds,
                usedRequiredApproverUserIds: $usedRequiredApproverUserIds,
                reservedNaturalManagerEmployeeIds: $protectNaturalManagers
                    ? $reservedNaturalManagerEmployeeIds
                    : [],
            );
        }

        $hierarchy = $this->resolveHierarchyAlternative(
            companyId: $companyId,
            requester: $requester,
            requesterEmployeeId: $requesterEmployeeId,
            requesterUserId: $requesterUserId,
            managementChain: $managementChain,
            usedManagerEmployeeIds: $usedManagerEmployeeIds,
            usedRequiredApproverEmployeeIds: $usedRequiredApproverEmployeeIds,
            usedRequiredApproverUserIds: $usedRequiredApproverUserIds,
            reservedNaturalManagerEmployeeIds: [],
        );

        if ($hierarchy !== null) {
            return $hierarchy;
        }

        return $this->resolveFallbackApprover(
            companyId: $companyId,
            requester: $requester,
            requesterEmployeeId: $requesterEmployeeId,
            requesterUserId: $requesterUserId,
            usedManagerEmployeeIds: $usedManagerEmployeeIds,
            usedRequiredApproverEmployeeIds: $usedRequiredApproverEmployeeIds,
            usedRequiredApproverUserIds: $usedRequiredApproverUserIds,
            settings: $settings,
            requireActionable: true,
        );
    }

    /**
     * @param  list<array{manager: Employee, manager_user: User|null, source_department: Department, is_direct: bool, hierarchy_order: int}>  $managementChain
     * @param  array<int, true>  $usedManagerEmployeeIds
     * @param  array<int, true>  $usedRequiredApproverEmployeeIds
     * @param  array<int, true>  $usedRequiredApproverUserIds
     * @param  array<int, true>  $reservedNaturalManagerEmployeeIds
     * @return array{employee: Employee, source_department: Department}|null
     */
    private function resolveHierarchyAlternative(
        int $companyId,
        Employee $requester,
        int $requesterEmployeeId,
        ?int $requesterUserId,
        array $managementChain,
        array $usedManagerEmployeeIds,
        array $usedRequiredApproverEmployeeIds,
        array $usedRequiredApproverUserIds,
        array $reservedNaturalManagerEmployeeIds,
    ): ?array {
        foreach ($managementChain as $entry) {
            $manager = $entry['manager'];
            $managerId = (int) $manager->id;

            if (isset($usedManagerEmployeeIds[$managerId])) {
                continue;
            }

            if (isset($reservedNaturalManagerEmployeeIds[$managerId])) {
                continue;
            }

            if ($this->sharesRequesterIdentity($manager, $requesterEmployeeId, $requesterUserId)) {
                continue;
            }

            if ($this->isAlreadyUsedRequiredApprover(
                $manager,
                $usedRequiredApproverEmployeeIds,
                $usedRequiredApproverUserIds,
            )) {
                continue;
            }

            if (! $this->isActionable($manager, $companyId, $requester, $requesterEmployeeId, $requesterUserId)) {
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
     * @param  array<int, true>  $usedManagerEmployeeIds
     * @param  array<int, true>  $usedRequiredApproverEmployeeIds
     * @param  array<int, true>  $usedRequiredApproverUserIds
     * @return array{employee: Employee, source_department: null}|null
     */
    private function resolveFallbackApprover(
        int $companyId,
        Employee $requester,
        int $requesterEmployeeId,
        ?int $requesterUserId,
        array $usedManagerEmployeeIds,
        array $usedRequiredApproverEmployeeIds,
        array $usedRequiredApproverUserIds,
        CompanyLeaveApprovalSetting $settings,
        bool $requireActionable,
    ): ?array {
        $fallbackId = $settings->fallback_approver_employee_id;

        if ($fallbackId === null) {
            return null;
        }

        $fallbackId = (int) $fallbackId;

        if (isset($usedManagerEmployeeIds[$fallbackId])) {
            return null;
        }

        $fallback = $this->loadCompanyEmployee($companyId, $fallbackId);

        if ($fallback === null) {
            return null;
        }

        if ($this->sharesRequesterIdentity($fallback, $requesterEmployeeId, $requesterUserId)) {
            return null;
        }

        if ($this->isAlreadyUsedRequiredApprover(
            $fallback,
            $usedRequiredApproverEmployeeIds,
            $usedRequiredApproverUserIds,
        )) {
            return null;
        }

        if (
            $requireActionable
            && ! $this->isActionable($fallback, $companyId, $requester, $requesterEmployeeId, $requesterUserId)
        ) {
            return null;
        }

        return [
            'employee' => $fallback,
            'source_department' => null,
        ];
    }

    /**
     * Reserve natural department/parent managers for later required manager steps so earlier
     * HR/Specific substitutes do not consume them when another eligible alternative exists.
     *
     * @param  iterable<int, LeaveApprovalPolicyStep>  $steps
     * @param  list<array{manager: Employee, manager_user: User|null, source_department: Department, is_direct: bool, hierarchy_order: int}>  $managementChain
     * @return array<int, true>
     */
    private function reserveNaturalManagerEmployeeIds(
        iterable $steps,
        int $companyId,
        Employee $requester,
        int $requesterEmployeeId,
        ?int $requesterUserId,
        array $managementChain,
    ): array {
        $reserved = [];
        $usedManagerEmployeeIds = [];

        foreach ($steps as $step) {
            if (! $step->is_required || ! $this->isManagerChainType($step->approver_type)) {
                continue;
            }

            foreach ($managementChain as $entry) {
                $manager = $entry['manager'];
                $managerId = (int) $manager->id;

                if (isset($usedManagerEmployeeIds[$managerId])) {
                    continue;
                }

                if ($this->sharesRequesterIdentity($manager, $requesterEmployeeId, $requesterUserId)) {
                    continue;
                }

                if (! $this->isActionable($manager, $companyId, $requester, $requesterEmployeeId, $requesterUserId)) {
                    continue;
                }

                $reserved[$managerId] = true;
                $usedManagerEmployeeIds[$managerId] = true;

                break;
            }
        }

        return $reserved;
    }

    private function sharesRequesterIdentity(
        Employee $employee,
        int $requesterEmployeeId,
        ?int $requesterUserId,
    ): bool {
        if ((int) $employee->id === $requesterEmployeeId) {
            return true;
        }

        if (
            $requesterUserId !== null
            && $employee->user_id !== null
            && (int) $employee->user_id === $requesterUserId
        ) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<int, true>  $usedRequiredApproverEmployeeIds
     * @param  array<int, true>  $usedRequiredApproverUserIds
     */
    private function isAlreadyUsedRequiredApprover(
        Employee $employee,
        array $usedRequiredApproverEmployeeIds,
        array $usedRequiredApproverUserIds,
    ): bool {
        if (isset($usedRequiredApproverEmployeeIds[(int) $employee->id])) {
            return true;
        }

        if (
            $employee->user_id !== null
            && isset($usedRequiredApproverUserIds[(int) $employee->user_id])
        ) {
            return true;
        }

        return false;
    }

    private function isActionable(
        Employee $employee,
        int $companyId,
        Employee $requester,
        int $requesterEmployeeId,
        ?int $requesterUserId,
    ): bool {
        if ((int) $employee->company_id !== $companyId) {
            return false;
        }

        if ($this->sharesRequesterIdentity($employee, $requesterEmployeeId, $requesterUserId)) {
            return false;
        }

        if (! $this->eligibility->evaluate($employee, $companyId)['actionable']) {
            return false;
        }

        $employee->loadMissing('user:id,name,email,status');

        return $this->visibility->assignedApproverCanActOnRequester(
            $employee->user,
            $requester,
            $companyId,
        );
    }

    /**
     * Notify-only (Required = OFF) recipients need an active identity in the company,
     * but must not require leave approve permission.
     */
    private function isNotifiableListener(
        Employee $employee,
        int $companyId,
        int $requesterEmployeeId,
        ?int $requesterUserId,
    ): bool {
        if ((int) $employee->company_id !== $companyId) {
            return false;
        }

        if ($this->sharesRequesterIdentity($employee, $requesterEmployeeId, $requesterUserId)) {
            return false;
        }

        return $this->eligibility->evaluate($employee, $companyId)['notifiable'];
    }

    private function isManagerChainType(LeaveApprovalApproverType $type): bool
    {
        return $type === LeaveApprovalApproverType::DepartmentManager
            || $type === LeaveApprovalApproverType::ParentManager;
    }

    /**
     * Prefer a required approval step when the same employee (or linked user) appears as both
     * required approver and notify-only listener. Never silently collapse two required steps.
     *
     * @param  list<array{step: LeaveApprovalPolicyStep, employee: Employee, user: User|null, source_department: Department|null}>  $resolved
     * @return list<array{step: LeaveApprovalPolicyStep, employee: Employee, user: User, source_department: Department|null}>
     */
    private function dedupeByEmployee(array $resolved): array
    {
        /** @var array<int, array{step: LeaveApprovalPolicyStep, employee: Employee, user: User, source_department: Department|null}> $byEmployee */
        $byEmployee = [];
        /** @var array<int, int> $employeeIdByUserId */
        $employeeIdByUserId = [];

        foreach ($resolved as $entry) {
            if ($entry['user'] === null) {
                continue;
            }

            $employeeId = (int) $entry['employee']->id;
            $userId = (int) $entry['user']->id;
            $incomingRequired = (bool) $entry['step']->is_required;

            $existingEmployeeId = $employeeIdByUserId[$userId] ?? null;

            if ($existingEmployeeId !== null && $existingEmployeeId !== $employeeId) {
                $existingByUser = $byEmployee[$existingEmployeeId] ?? null;

                if ($existingByUser !== null) {
                    $existingRequired = (bool) $existingByUser['step']->is_required;

                    if ($existingRequired && $incomingRequired) {
                        throw new RuntimeException(self::SELF_APPROVAL_ALTERNATIVE_REQUIRED_MESSAGE);
                    }

                    if (! $existingRequired && $incomingRequired) {
                        unset($byEmployee[$existingEmployeeId]);
                        unset($employeeIdByUserId[$userId]);
                    } else {
                        continue;
                    }
                }
            }

            $existing = $byEmployee[$employeeId] ?? null;

            if ($existing === null) {
                $byEmployee[$employeeId] = $entry;
                $employeeIdByUserId[$userId] = $employeeId;

                continue;
            }

            $existingRequired = (bool) $existing['step']->is_required;

            if ($existingRequired && $incomingRequired) {
                throw new RuntimeException(self::SELF_APPROVAL_ALTERNATIVE_REQUIRED_MESSAGE);
            }

            if (! $existingRequired && $incomingRequired) {
                $byEmployee[$employeeId] = $entry;
                $employeeIdByUserId[$userId] = $employeeId;
            }
        }

        return array_values($byEmployee);
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
