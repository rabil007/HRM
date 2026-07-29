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
        $context = DepartmentHierarchyContext::forCompany($companyId);

        // Prefer the request-scoped hierarchy context. When a caller already loaded
        // departmentsById, ensure the context map is used (same company load once).
        unset($departmentsById);

        return $context->present($department);
    }
}
