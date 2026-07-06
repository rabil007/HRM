export const SALARY_PAYMENT_METHOD_OPTIONS = [
    { value: 'bank_transfer', label: 'Bank transfer' },
    { value: 'cash_c3', label: 'C3' },
    { value: 'cash_ansari', label: 'Ansari' },
    { value: 'cash_other', label: 'Cash' },
    { value: 'third_party', label: 'Third party' },
] as const;

export type SalaryPaymentMethodValue =
    (typeof SALARY_PAYMENT_METHOD_OPTIONS)[number]['value'];

export function cashPaymentBadgeLabel(
    method: SalaryPaymentMethodValue,
): string | null {
    switch (method) {
        case 'cash_c3':
            return 'C3';
        case 'cash_ansari':
            return 'Ansari';
        case 'cash_other':
            return 'Cash';
        default:
            return null;
    }
}

export function requiresBankAccount(method: SalaryPaymentMethodValue): boolean {
    return method === 'bank_transfer';
}

export function isCashPaymentMethod(method: SalaryPaymentMethodValue): boolean {
    return (
        method === 'cash_c3' ||
        method === 'cash_ansari' ||
        method === 'cash_other'
    );
}
