<?php

namespace App\Enums;

enum DocumentRecipientType: string
{
    case SubjectEmployee = 'subject_employee';

    public function label(): string
    {
        return match ($this) {
            self::SubjectEmployee => 'Subject employee',
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
