/**
 * Financial Payment column/card on the Payroll index is for payroll.periods.view users only.
 * Crew Ops (view_financial === false) must not see Payment / Pending at all.
 */
export function payrollIndexShowPayment(
    viewFinancial: boolean | undefined,
): boolean {
    return viewFinancial !== false;
}
