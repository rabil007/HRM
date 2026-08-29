<?php

namespace App\Enums;

enum DocumentSigningTargetType: string
{
    case SubjectEmployee = 'subject_employee';
    case DepartmentManager = 'department_manager';
    case SpecificUser = 'specific_user';

    public function label(): string
    {
        return match ($this) {
            self::SubjectEmployee => 'Subject employee',
            self::DepartmentManager => 'Department manager',
            self::SpecificUser => 'Specific user',
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
