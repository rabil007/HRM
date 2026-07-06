import { Link, router } from '@inertiajs/react';
import { ArrowLeft, UserX } from 'lucide-react';
import { useState } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import type { NoBankAccountEmployee, NoBankAccountIndexProps } from '@/features/organization/bank-accounts/types';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { cashPaymentBadgeLabel } from '@/features/organization/employees/salary-payment-method';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { bankAccounts } from '@/routes/organization';
import { noAccount, employee as bankAccountEmployee } from '@/routes/organization/bank-accounts';

function NoBankAccountTableRow({ emp }: { emp: NoBankAccountEmployee }) {
    const cashBadge = emp.salary_payment_method
        ? cashPaymentBadgeLabel(emp.salary_payment_method)
        : null;

    return (
        <TableRow
            className={cn(dataTableBodyRowClass(false), 'cursor-pointer')}
            onClick={() =>
                router.visit(
                    bankAccountEmployee.url({ employee: emp.id }, { query: { from: 'no-account' } }),
                )
            }
        >
            <TableCell className={cn(dataTableCellPrimaryClass(), 'min-w-[200px]')}>
                <div className="flex min-w-0 items-center gap-3">
                    <EmployeeAvatar name={emp.name} image={emp.image} size="sm" />
                    <div className="min-w-0">
                        <p className="truncate text-sm font-semibold text-foreground">{emp.name}</p>
                        <p className="truncate font-mono text-[11px] text-muted-foreground/75">
                            {emp.employee_no}
                        </p>
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
                        {emp.salary_payment_method_label || 'Bank transfer'}
                    </span>
                )}
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                <span className="text-sm text-muted-foreground">{emp.department ?? '—'}</span>
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                <span className="text-sm text-muted-foreground">{emp.position ?? '—'}</span>
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                <span className="text-sm text-muted-foreground">
                    {emp.hire_date ? formatDisplayDate(emp.hire_date) : '—'}
                </span>
            </TableCell>
        </TableRow>
    );
}

export function BankAccountsNoAccountContent({
    employees,
    pagination,
    search: initialSearch,
}: NoBankAccountIndexProps) {
    const [searchInput, setSearchInput] = useState(initialSearch);

    function onSearchChange(value: string) {
        setSearchInput(value);
        router.get(
            noAccount.url(),
            { search: value || undefined },
            { preserveState: true, preserveScroll: true },
        );
    }

    function onPageChange(page: number) {
        router.get(
            noAccount.url(),
            { search: searchInput || undefined, page },
            { preserveState: true, preserveScroll: true },
        );
    }

    return (
        <Main>
            <PageHeader
                title="No Bank Account Employees"
                description="Employees who have no bank accounts assigned."
                right={
                    <Link
                        href={bankAccounts.url()}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" aria-hidden />
                        Back to bank accounts
                    </Link>
                }
            />

            <SearchBar
                value={searchInput}
                onChange={onSearchChange}
                placeholder="Search by name or employee number..."
            />

            {employees.length === 0 ? (
                <EmptyState
                    icon={<UserX className="size-10 text-muted-foreground/40" />}
                    title="No employees found"
                    description={
                        searchInput
                            ? 'No employees match your search.'
                            : 'All employees have at least one bank account assigned.'
                    }
                />
            ) : (
                <div className="space-y-4">
                    <OrganizationDataTable minWidth="min-w-[940px]" compact>
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead>Employee</DataTableHead>
                                <DataTableHead>Payment Type</DataTableHead>
                                <DataTableHead>Department</DataTableHead>
                                <DataTableHead>Position</DataTableHead>
                                <DataTableHead>Hire date</DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {employees.map((emp) => (
                                <NoBankAccountTableRow key={emp.id} emp={emp} />
                            ))}
                        </TableBody>
                    </OrganizationDataTable>

                    {pagination.last_page > 1 ? (
                        <Pagination
                            currentPage={pagination.current_page}
                            lastPage={pagination.last_page}
                            from={pagination.from}
                            to={pagination.to}
                            total={pagination.total}
                            perPage={pagination.per_page}
                            onPageChange={onPageChange}
                            label="employees"
                        />
                    ) : null}
                </div>
            )}
        </Main>
    );
}
