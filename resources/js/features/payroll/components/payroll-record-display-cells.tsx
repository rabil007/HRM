import { AlertCircle, CreditCard } from 'lucide-react';
import { dataTableCellClass } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { TableCell } from '@/components/ui/table';
import {
    cashPaymentBadgeLabel,
    isCashPaymentMethod,
} from '@/features/organization/employees/salary-payment-method';
import type { SalaryPaymentMethodValue } from '@/features/organization/employees/salary-payment-method';
import type { OfficePrimaryAccount } from '../types';

export function PayrollRecordPaymentMethodCell({
    method,
    label,
}: {
    method: SalaryPaymentMethodValue;
    label: string;
}) {
    const cashBadge = cashPaymentBadgeLabel(method);

    return (
        <TableCell className={dataTableCellClass()}>
            {cashBadge ? (
                <Badge
                    variant="outline"
                    className="border-amber-500/30 bg-amber-500/10 text-xs font-semibold text-amber-800 dark:text-amber-200"
                >
                    {cashBadge}
                </Badge>
            ) : (
                <span className="text-xs text-muted-foreground">{label}</span>
            )}
        </TableCell>
    );
}

export function PayrollRecordBankAccountCell({
    primary_account,
    salary_payment_method,
}: {
    primary_account: OfficePrimaryAccount | null;
    salary_payment_method: SalaryPaymentMethodValue;
}) {
    const paysByCash = isCashPaymentMethod(salary_payment_method);
    const hasBankAccount =
        primary_account !== null && primary_account !== undefined;
    const hasIban = !!primary_account?.iban;

    return (
        <TableCell className={dataTableCellClass()}>
            {paysByCash ? (
                <span className="text-xs text-muted-foreground">—</span>
            ) : hasBankAccount ? (
                <div className="space-y-0.5">
                    <div className="inline-flex items-center gap-1.5 text-sm">
                        <CreditCard className="h-3.5 w-3.5 shrink-0 text-emerald-500" />
                        {primary_account.bank_name ?? '—'}
                    </div>
                    {hasIban ? (
                        <div className="font-mono text-xs text-muted-foreground">
                            {primary_account.iban}
                        </div>
                    ) : (
                        <div className="inline-flex items-center gap-1.5 text-xs text-amber-600 dark:text-amber-400">
                            <AlertCircle className="h-3.5 w-3.5 shrink-0" />
                            IBAN not set
                        </div>
                    )}
                </div>
            ) : (
                <span className="inline-flex items-center gap-1.5 text-xs text-amber-600 dark:text-amber-400">
                    <AlertCircle className="h-3.5 w-3.5 shrink-0" />
                    Not set
                </span>
            )}
        </TableCell>
    );
}
