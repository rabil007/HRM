<?php

namespace App\Enums;

enum LeaveApprovalApproverType: string
{
    case DepartmentManager = 'department_manager';
    case ParentManager = 'parent_manager';
    case HrApprover = 'hr_approver';
    case SpecificEmployee = 'specific_employee';

    public function label(): string
    {
        return match ($this) {
            self::DepartmentManager => 'Department Manager',
            self::ParentManager => 'Parent Manager',
            self::HrApprover => 'HR Approver',
            self::SpecificEmployee => 'Specific Employee',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function requiresEmployeeSelection(): bool
    {
        return $this === self::SpecificEmployee;
    }

    public function allowsOptionalEmployeeOverride(): bool
    {
        return $this === self::HrApprover;
    }
}
