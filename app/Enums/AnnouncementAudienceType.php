<?php

namespace App\Enums;

enum AnnouncementAudienceType: string
{
    case AllEmployees = 'all_employees';
    case Department = 'department';
    case Branch = 'branch';
    case Position = 'position';
    case Employee = 'employee';

    public function label(): string
    {
        return match ($this) {
            self::AllEmployees => 'All active employees',
            self::Department => 'Department',
            self::Branch => 'Branch',
            self::Position => 'Position',
            self::Employee => 'Employee',
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
