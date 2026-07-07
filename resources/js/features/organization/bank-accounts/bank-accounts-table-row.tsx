import { router } from '@inertiajs/react';
import {
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { TableCell, TableRow } from '@/components/ui/table';
import type { BankAccountListItem } from '@/features/organization/bank-accounts/types';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { cashPaymentBadgeLabel } from '@/features/organization/employees/salary-payment-method';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

export function BankAccountsTableRow({
    bankAccount,
    browseHref,
}: {
    bankAccount: BankAccountListItem;
    browseHref: string;
}) {
    const cashBadge = bankAccount.salary_payment_method
        ? cashPaymentBadgeLabel(bankAccount.salary_payment_method)
        : null;

    return (
        <TableRow
            className={cn(dataTableBodyRowClass(false), 'cursor-pointer')}
            onClick={() => router.visit(browseHref)}
        >
            <TableCell
                className={cn(dataTableCellPrimaryClass(), 'min-w-[180px]')}
            >
                <div className="flex min-w-0 items-center gap-3">
                    <EmployeeProfileLink
                        employeeId={bankAccount.employee_id}
                        stopRowNavigation
                        className="shrink-0"
                    >
                        <EmployeeAvatar
                            name={bankAccount.employee_name}
                            image={bankAccount.employee_image}
                            size="sm"
                        />
                    </EmployeeProfileLink>
                    <div className="min-w-0">
                        <EmployeeProfileLink
                            employeeId={bankAccount.employee_id}
                            className="block truncate text-sm font-semibold text-foreground hover:text-primary"
                            stopRowNavigation
                        >
                            {bankAccount.employee_name}
                        </EmployeeProfileLink>
                        <p className="truncate font-mono text-[11px] text-muted-foreground/75">
                            {bankAccount.employee_no}
                        </p>
                        {(bankAccount.department_name || bankAccount.position_title) ? (
                            <p className="truncate text-[11px] text-muted-foreground/60">
                                {[bankAccount.department_name, bankAccount.position_title]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        ) : null}
                    </div>
                </div>
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {cashBadge ? (
                    <Badge
                        variant="outline"
                        className="border-amber-500/30 bg-amber-500/10 text-xs font-semibold text-amber-800 dark:text-amber-200"
                    >
                        {cashBadge}
                    </Badge>
                ) : (
                    <span className="text-sm text-muted-foreground">
                        {bankAccount.salary_payment_method_label || 'Bank transfer'}
                    </span>
                )}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                <span className="tabular-nums text-sm text-muted-foreground">
                    {bankAccount.total_bank_accounts}
                </span>
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                <span className="font-medium text-foreground">
                    {bankAccount.bank_name || '—'}
                </span>
                {bankAccount.bank_routing_code ? (
                    <p className="font-mono text-[11px] text-muted-foreground/60">
                        {bankAccount.bank_routing_code}
                    </p>
                ) : null}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {bankAccount.account_name || '—'}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                <span className="font-mono text-xs tracking-wide">
                    {bankAccount.iban || '—'}
                </span>
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {bankAccount.is_primary ? (
                    <Badge
                        variant="outline"
                        className="border-emerald-500/30 bg-emerald-500/10 text-emerald-400 font-normal"
                    >
                        Primary
                    </Badge>
                ) : (
                    <Badge
                        variant="outline"
                        className="border-sky-500/30 bg-sky-500/10 text-sky-400 font-normal"
                    >
                        Secondary
                    </Badge>
                )}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                {formatDisplayDate(bankAccount.created_at)}
            </TableCell>
        </TableRow>
    );
}
