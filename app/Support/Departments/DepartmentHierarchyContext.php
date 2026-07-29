<?php

namespace App\Support\Departments;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveApprovalPolicy;
use Illuminate\Support\Collection;

/**
 * Company-scoped department hierarchy context loaded once per HTTP request.
 *
 * @phpstan-type ManagerAssignment array{
 *     type: 'direct'|'inherited'|'none',
 *     label: string,
 *     source_department: array{id: int, name: string}|null
 * }
 * @phpstan-type PolicyAssignment array{
 *     type: 'direct'|'inherited'|'company_default'|'none',
 *     label: string,
 *     source_department: array{id: int, name: string}|null
 * }
 */
final class DepartmentHierarchyContext
{
    /** @var array<int, self> */
    private static array $instances = [];

    /**
     * @param  Collection<int, Department>  $departmentsById
     * @param  Collection<int, LeaveApprovalPolicy>  $policiesById
     */
    public function __construct(
        public readonly int $companyId,
        public readonly Collection $departmentsById,
        public readonly Collection $policiesById,
        public readonly ?LeaveApprovalPolicy $companyDefaultPolicy,
    ) {}

    public static function forCompany(int $companyId): self
    {
        if (isset(self::$instances[$companyId])) {
            return self::$instances[$companyId];
        }

        $departmentsById = Department::query()
            ->where('company_id', $companyId)
            ->with([
                'manager:id,company_id,name,employee_no,user_id,status',
                'leaveApprovalPolicy:id,company_id,name,status,is_default',
            ])
            ->get([
                'id',
                'company_id',
                'parent_id',
                'manager_id',
                'leave_approval_policy_id',
                'name',
                'code',
                'status',
            ])
            ->keyBy('id');

        $policiesById = LeaveApprovalPolicy::query()
            ->where('company_id', $companyId)
            ->get(['id', 'company_id', 'name', 'status', 'is_default'])
            ->keyBy('id');

        $companyDefaultPolicy = $policiesById
            ->first(fn (LeaveApprovalPolicy $policy): bool => $policy->is_default && $policy->status === 'active');

        return self::$instances[$companyId] = new self(
            companyId: $companyId,
            departmentsById: $departmentsById,
            policiesById: $policiesById,
            companyDefaultPolicy: $companyDefaultPolicy,
        );
    }

    /**
     * Test/request isolation helper.
     */
    public static function flush(): void
    {
        self::$instances = [];
    }

    /**
     * @return array{
     *     manager: array{id: int, name: string|null, employee_no: string|null}|null,
     *     manager_assignment: ManagerAssignment,
     *     leave_approval_policy: array{id: int, name: string}|null,
     *     leave_approval_policy_assignment: PolicyAssignment,
     *     leave_approval_policy_warning: string|null
     * }
     */
    public function present(Department $department): array
    {
        $managerDetail = $this->effectiveManagerDetail((int) $department->id);
        $policyDetail = $this->effectivePolicyDetail((int) $department->id);

        $managerPayload = null;
        $managerAssignment = [
            'type' => 'none',
            'label' => 'No manager configured',
            'source_department' => null,
        ];

        if ($managerDetail !== null && $managerDetail['manager'] !== null) {
            $manager = $managerDetail['manager'];
            $managerPayload = [
                'id' => (int) $manager->id,
                'name' => $manager->name,
                'employee_no' => $manager->employee_no,
            ];

            if ($managerDetail['is_direct']) {
                $managerAssignment = [
                    'type' => 'direct',
                    'label' => 'Direct manager',
                    'source_department' => null,
                ];
            } elseif ($managerDetail['source_department'] !== null) {
                $source = $managerDetail['source_department'];
                $managerAssignment = [
                    'type' => 'inherited',
                    'label' => 'Inherited from '.$source->name,
                    'source_department' => [
                        'id' => (int) $source->id,
                        'name' => (string) $source->name,
                    ],
                ];
            }
        }

        $policyPayload = null;
        $policyAssignment = [
            'type' => 'none',
            'label' => 'No approval policy configured',
            'source_department' => null,
        ];
        $policyWarning = null;

        if ($policyDetail['policy'] !== null) {
            $policy = $policyDetail['policy'];
            $policyPayload = [
                'id' => (int) $policy->id,
                'name' => (string) $policy->name,
            ];

            $sourceDepartment = $policyDetail['source_department'];

            $policyAssignment = match ($policyDetail['source']) {
                'direct' => [
                    'type' => 'direct',
                    'label' => 'Directly configured',
                    'source_department' => null,
                ],
                'inherited' => [
                    'type' => 'inherited',
                    'label' => $sourceDepartment !== null
                        ? 'Inherited from '.$sourceDepartment->name
                        : 'Inherited from parent department',
                    'source_department' => $sourceDepartment !== null
                        ? ['id' => (int) $sourceDepartment->id, 'name' => (string) $sourceDepartment->name]
                        : null,
                ],
                'company_default' => [
                    'type' => 'company_default',
                    'label' => 'Company default',
                    'source_department' => null,
                ],
                default => $policyAssignment,
            };
        }

        $directPolicyId = $department->leave_approval_policy_id !== null
            ? (int) $department->leave_approval_policy_id
            : null;

        if ($directPolicyId !== null) {
            $directPolicy = $this->policiesById->get($directPolicyId);

            if ($directPolicy === null || $directPolicy->status !== 'active') {
                $policyWarning = 'This department references an inactive or missing leave approval policy. Runtime resolution falls back to an ancestor or company default when available.';
            }
        }

        return [
            'manager' => $managerPayload,
            'manager_assignment' => $managerAssignment,
            'leave_approval_policy' => $policyPayload,
            'leave_approval_policy_assignment' => $policyAssignment,
            'leave_approval_policy_warning' => $policyWarning,
        ];
    }

    /**
     * @return array{
     *     manager: Employee|null,
     *     source_department: Department|null,
     *     is_direct: bool,
     *     is_inherited: bool
     * }|null
     */
    public function effectiveManagerDetail(int $departmentId): ?array
    {
        $department = $this->departmentsById->get($departmentId);

        if ($department === null) {
            return null;
        }

        if ($department->manager_id !== null) {
            return [
                'manager' => $department->manager,
                'source_department' => $department,
                'is_direct' => true,
                'is_inherited' => false,
            ];
        }

        $visited = [];
        $currentId = $department->parent_id !== null ? (int) $department->parent_id : null;

        while ($currentId !== null && ! isset($visited[$currentId])) {
            $visited[$currentId] = true;
            $ancestor = $this->departmentsById->get($currentId);

            if ($ancestor === null) {
                break;
            }

            if ($ancestor->manager_id !== null) {
                return [
                    'manager' => $ancestor->manager,
                    'source_department' => $ancestor,
                    'is_direct' => false,
                    'is_inherited' => true,
                ];
            }

            $currentId = $ancestor->parent_id !== null ? (int) $ancestor->parent_id : null;
        }

        return [
            'manager' => null,
            'source_department' => null,
            'is_direct' => false,
            'is_inherited' => false,
        ];
    }

    /**
     * @return array{
     *     policy: LeaveApprovalPolicy|null,
     *     source: 'direct'|'inherited'|'company_default'|'none',
     *     source_department: Department|null
     * }
     */
    public function effectivePolicyDetail(int $departmentId): array
    {
        $department = $this->departmentsById->get($departmentId);

        if ($department === null) {
            return [
                'policy' => $this->companyDefaultPolicy,
                'source' => $this->companyDefaultPolicy !== null ? 'company_default' : 'none',
                'source_department' => null,
            ];
        }

        $direct = $this->activePolicyForDepartment($department);

        if ($direct !== null) {
            return [
                'policy' => $direct,
                'source' => 'direct',
                'source_department' => $department,
            ];
        }

        $visited = [$departmentId => true];
        $currentId = $department->parent_id !== null ? (int) $department->parent_id : null;

        while ($currentId !== null && ! isset($visited[$currentId])) {
            $visited[$currentId] = true;
            $ancestor = $this->departmentsById->get($currentId);

            if ($ancestor === null) {
                break;
            }

            $inherited = $this->activePolicyForDepartment($ancestor);

            if ($inherited !== null) {
                return [
                    'policy' => $inherited,
                    'source' => 'inherited',
                    'source_department' => $ancestor,
                ];
            }

            $currentId = $ancestor->parent_id !== null ? (int) $ancestor->parent_id : null;
        }

        return [
            'policy' => $this->companyDefaultPolicy,
            'source' => $this->companyDefaultPolicy !== null ? 'company_default' : 'none',
            'source_department' => null,
        ];
    }

    private function activePolicyForDepartment(Department $department): ?LeaveApprovalPolicy
    {
        if ($department->leave_approval_policy_id === null) {
            return null;
        }

        $policy = $this->policiesById->get((int) $department->leave_approval_policy_id);

        if ($policy === null || $policy->status !== 'active' || (int) $policy->company_id !== $this->companyId) {
            return null;
        }

        return $policy;
    }
}
