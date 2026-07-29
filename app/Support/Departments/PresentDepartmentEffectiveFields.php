<?php

namespace App\Support\Departments;

use App\Models\Department;
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
     *     },
     *     leave_approval_policy_warning: string|null
     * }
     */
    public static function forDepartment(Department $department, Collection $departmentsById, int $companyId): array
    {
        return self::forDepartmentWithContext(
            $department,
            DepartmentHierarchyContext::fromDepartments($companyId, $departmentsById),
        );
    }

    /**
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
     *     },
     *     leave_approval_policy_warning: string|null
     * }
     */
    public static function forDepartmentWithContext(Department $department, DepartmentHierarchyContext $context): array
    {
        return $context->present($department);
    }
}
