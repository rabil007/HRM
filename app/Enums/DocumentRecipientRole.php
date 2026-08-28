<?php

namespace App\Enums;

enum DocumentRecipientRole: string
{
    case Subject = 'subject';
    case CompanySignatory = 'company_signatory';

    public function label(): string
    {
        return match ($this) {
            self::Subject => 'Subject employee',
            self::CompanySignatory => 'Company signatory',
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
