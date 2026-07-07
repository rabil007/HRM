import { Link, router } from '@inertiajs/react';
import { ArrowLeft, Loader2, UserX } from 'lucide-react';
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
import { Badge } from '@/components/ui/badge';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { NoAccountSummaryCards } from '@/features/organization/bank-accounts/no-account-summary-cards';
import type { NoBankAccountEmployee, NoBankAccountIndexProps } from '@/features/organization/bank-accounts/types';
import { useNoBankAccountIndexFilters } from '@/features/organization/bank-accounts/use-no-bank-account-index-filters';
import { DepartmentFilterControls } from '@/features/organization/employees/components/department-filter-controls';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
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
                    <EmployeeProfileLink
                        employeeId={emp.id}
                        stopRowNavigation
                        className="shrink-0"
                    >
                        <EmployeeAvatar name={emp.name} image={emp.image} size="sm" />
                    </EmployeeProfileLink>
                    <div className="min-w-0">
                        <EmployeeProfileLink
                            employeeId={emp.id}
                            className="block truncate text-sm font-semibold text-foreground hover:text-primary"
                            stopRowNavigation
                        >
                            {emp.name}
                        </EmployeeProfileLink>
                        <p className="truncate font-mono text-[11px] text-muted-foreground/75">
                            {emp.employee_no}
                        </p>
                        {(emp.department || emp.position) ? (
                            <p className="truncate text-[11px] text-muted-foreground/60">
                                {[emp.department, emp.position]
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
                        {emp.salary_payment_method_label || 'Bank transfer'}
                    </span>
                )}
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
    summary,
    employees,
    pagination,
    search: initialSearch,
    payment_method = '',
    department_id = '',
    department_tree = [],
    department_tree_selected_id = null,
}: NoBankAccountIndexProps) {
    const {
        searchInput,
        isSearching,
        onSearchChange,
        onFilterChange,
        onDepartmentChange,
        onPageChange,
    } = useNoBankAccountIndexFilters({
        url: noAccount.url(),
        initialSearch,
        initialPaymentMethod: payment_method,
        initialDepartmentId: department_id,
        perPage: pagination.per_page,
    });

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

            <NoAccountSummaryCards
                summary={summary}
                activeFilter={payment_method}
                onSelect={onFilterChange}
            />

            <SearchBar
                placeholder="Search by name or employee number..."
                value={searchInput}
                onChange={onSearchChange}
                right={
                    <div className="flex items-center gap-3">
                        {isSearching ? (
                            <Loader2
                                className="size-4 animate-spin text-muted-foreground"
                                aria-hidden
                            />
                        ) : null}

                        <DepartmentFilterControls
                            department_tree={department_tree}
                            department_tree_selected_id={department_tree_selected_id}
                            department_tree_selected_position_id={null}
                            onSelectDepartment={onDepartmentChange}
                            onSelectPosition={(_, depId) =>
                                onDepartmentChange(depId)
                            }
                        />
                    </div>
                }
            />

            {employees.length === 0 ? (
                <EmptyState
                    icon={<UserX className="size-10 text-muted-foreground/40" />}
                    title="No employees found"
                    description={
                        searchInput || payment_method || department_id
                            ? 'No employees match your search or filter.'
                            : 'All employees have at least one bank account assigned.'
                    }
                />
            ) : (
                <div className="space-y-4">
                    <OrganizationDataTable minWidth="min-w-[640px]" compact>
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead>Employee</DataTableHead>
                                <DataTableHead>Payment Type</DataTableHead>
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
