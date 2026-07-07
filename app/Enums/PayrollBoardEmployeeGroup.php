<?php

namespace App\Enums;

enum PayrollBoardEmployeeGroup: string
{
    case Total = '';
    case WithBankAccount = 'with_bank_account';
    /** All non-bank salary payment methods (C3, Ansari, Cash, third party). */
    case CashPayment = 'cash_payment';
    case MissingBankAccount = 'missing_bank_account';

    public static function fromQuery(mixed $value): self
    {
        return match ((string) $value) {
            self::WithBankAccount->value => self::WithBankAccount,
            self::CashPayment->value => self::CashPayment,
            self::MissingBankAccount->value => self::MissingBankAccount,
            default => self::Total,
        };
    }

    public function isActive(): bool
    {
        return $this !== self::Total;
    }
}
