<?php

namespace App\Enums;

enum SalaryPaymentMethod: string
{
    case BankTransfer = 'bank_transfer';
    case CashC3 = 'cash_c3';
    case CashAnsari = 'cash_ansari';
    case CashOther = 'cash_other';
    case ThirdParty = 'third_party';

    public function label(): string
    {
        return match ($this) {
            self::BankTransfer => 'Bank transfer',
            self::CashC3 => 'C3',
            self::CashAnsari => 'Ansari',
            self::CashOther => 'Cash',
            self::ThirdParty => 'Third party',
        };
    }

    public function requiresBankAccount(): bool
    {
        return $this === self::BankTransfer;
    }

    public function isCash(): bool
    {
        return match ($this) {
            self::BankTransfer, self::ThirdParty => false,
            self::CashC3, self::CashAnsari, self::CashOther => true,
        };
    }

    public function excludesFromWps(): bool
    {
        return ! $this->requiresBankAccount();
    }

    public function wpsSkipReason(): string
    {
        return match ($this) {
            self::CashC3 => 'Salary paid via C3 — excluded from WPS.',
            self::CashAnsari => 'Salary paid via Ansari — excluded from WPS.',
            self::CashOther => 'Salary paid via cash — excluded from WPS.',
            self::ThirdParty => 'Salary paid via third party — excluded from WPS.',
            self::BankTransfer => '',
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
