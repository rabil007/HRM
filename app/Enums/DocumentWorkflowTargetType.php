<?php

namespace App\Enums;

enum DocumentWorkflowTargetType: string
{
    case SpecificUser = 'specific_user';
    case DepartmentManager = 'department_manager';
    case ParentManager = 'parent_manager';
    case CompanyRole = 'company_role';

    public function label(): string
    {
        return match ($this) {
            self::SpecificUser => 'Specific user',
            self::DepartmentManager => 'Department manager',
            self::ParentManager => 'Parent manager',
            self::CompanyRole => 'Company role',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
