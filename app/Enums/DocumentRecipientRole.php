<?php

namespace App\Enums;

enum DocumentRecipientRole: string
{
    case Subject = 'subject';
    case Manager = 'manager';
    case CompanySignatory = 'company_signatory';

    public function label(): string
    {
        return match ($this) {
            self::Subject => 'Subject employee',
            self::Manager => 'Department manager',
            self::CompanySignatory => 'Company signatory',
        };
    }

    public function isInternalSigner(): bool
    {
        return match ($this) {
            self::Manager, self::CompanySignatory => true,
            self::Subject => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<string>
     */
    public static function internalSignerValues(): array
    {
        return [
            self::Manager->value,
            self::CompanySignatory->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function signaturePlacementValues(): array
    {
        return self::values();
    }
}
