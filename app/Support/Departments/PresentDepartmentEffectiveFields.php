<?php

namespace App\Support\Departments;

use App\Models\Department;
use App\Support\Attendance\Data\EffectiveLeaveApprovalPolicy;
use App\Support\Attendance\ResolveLeaveApprovalPolicy;
use Illuminate\Support\Collection;

final class PresentDepartmentEffectiveFields
{
    /**
     * @param  Collection<int, Department>  $departmentsById
     * @return array{
     *     manager: array{id: int, name: string|null, employee_no: string|null}|null,
     *     manager_assignment: array{
     *         type: 'direct'|'inherited'|'none',
     *         label: string,
     *         source_department: array{id: int, name: string}|null
     *     },
     *     leave_approval_policy: array{id: int, name: string}|null,
     *     leave_approval_policy_assignment: array{
     *         type: 'direct'|'inherited'|'company_default'|'none',
     *         label: string,
     *         source_department: array{id: int, name: string}|null
     *     }
     * }
     */
    public static function forDepartment(Department $department, Collection $departmentsById, int $companyId): array
    {
        $managerDetail = ResolveDepartmentManagementChain::effectiveManagerDetail($companyId, (int) $department->id);

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

        $effectivePolicy = app(ResolveLeaveApprovalPolicy::class)->forDepartment($companyId, (int) $department->id);

        $policyPayload = null;
        $policyAssignment = [
            'type' => 'none',
            'label' => 'No approval policy configured',
            'source_department' => null,
        ];

        if ($effectivePolicy instanceof EffectiveLeaveApprovalPolicy) {
            $policy = $effectivePolicy->policy;
            $policyPayload = [
                'id' => (int) $policy->id,
                'name' => (string) $policy->name,
            ];

            $sourceDepartment = $effectivePolicy->sourceDepartment;

            $policyAssignment = match ($effectivePolicy->source) {
                EffectiveLeaveApprovalPolicy::SOURCE_DIRECT => [
                    'type' => 'direct',
                    'label' => 'Directly configured',
                    'source_department' => null,
                ],
                EffectiveLeaveApprovalPolicy::SOURCE_INHERITED => [
                    'type' => 'inherited',
                    'label' => $sourceDepartment !== null
                        ? 'Inherited from '.$sourceDepartment->name
                        : 'Inherited from parent department',
                    'source_department' => $sourceDepartment !== null
                        ? ['id' => (int) $sourceDepartment->id, 'name' => (string) $sourceDepartment->name]
                        : null,
                ],
                EffectiveLeaveApprovalPolicy::SOURCE_COMPANY_DEFAULT => [
                    'type' => 'company_default',
                    'label' => 'Company default',
                    'source_department' => null,
                ],
                default => $policyAssignment,
            };
        }

        return [
            'manager' => $managerPayload,
            'manager_assignment' => $managerAssignment,
            'leave_approval_policy' => $policyPayload,
            'leave_approval_policy_assignment' => $policyAssignment,
        ];
    }
}
