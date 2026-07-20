<?php

namespace App\Enums;

enum CrewTimesheetPreparationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Approved = 'approved';
    case Applied = 'applied';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::Returned => 'Returned',
            self::Approved => 'Approved',
            self::Applied => 'Applied',
            self::Superseded => 'Superseded',
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
