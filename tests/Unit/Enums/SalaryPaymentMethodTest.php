<?php

use App\Enums\SalaryPaymentMethod;

test('salary payment method enum helpers', function () {
    expect(SalaryPaymentMethod::BankTransfer->isCash())->toBeFalse()
        ->and(SalaryPaymentMethod::BankTransfer->excludesFromWps())->toBeFalse()
        ->and(SalaryPaymentMethod::CashC3->isCash())->toBeTrue()
        ->and(SalaryPaymentMethod::CashC3->excludesFromWps())->toBeTrue()
        ->and(SalaryPaymentMethod::CashC3->wpsSkipReason())
        ->toBe('Salary paid via C3 — excluded from WPS.')
        ->and(SalaryPaymentMethod::CashAnsari->wpsSkipReason())
        ->toBe('Salary paid via Ansari — excluded from WPS.');
});
