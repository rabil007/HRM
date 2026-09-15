<?php

namespace App\Enums;

enum CrewAssignmentSubmissionIntent: string
{
    case Start = 'start';
    case Draft = 'draft';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_values(array_column(self::cases(), 'value'));
    }
}
