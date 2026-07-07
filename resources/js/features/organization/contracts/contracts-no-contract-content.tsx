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
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import type { NoContractEmployee, NoContractIndexProps } from '@/features/organization/contracts/types';
import { useNoContractIndexFilters } from '@/features/organization/contracts/use-no-contract-index-filters';
import { EmployeeAvatar } from '@/features/organization/employees/components/employee-avatar';
import { DepartmentFilterControls } from '@/features/organization/employees/components/department-filter-controls';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { contracts } from '@/routes/organization';
import { noContract, employee as contractEmployee } from '@/routes/organization/contracts';

function NoContractTableRow({ emp }: { emp: NoContractEmployee }) {
    return (
        <TableRow
            className={cn(dataTableBodyRowClass(false), 'cursor-pointer')}
            onClick={() =>
                router.visit(
                    contractEmployee.url({ employee: emp.id }, { query: { from: 'no-contract' } }),
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
                <span className="text-sm text-muted-foreground">
                    {emp.hire_date ? formatDisplayDate(emp.hire_date) : '—'}
                </span>
            </TableCell>
        </TableRow>
    );
}

export function ContractsNoContractContent({
    employees,
    pagination,
    search: initialSearch,
    department_id: initialDepartmentId = '',
    department_tree,
    department_tree_selected_id,
}: NoContractIndexProps) {
    const {
        searchInput,
        isSearching,
        onSearchChange,
        onDepartmentChange,
        onPageChange,
    } = useNoContractIndexFilters({
        url: noContract.url(),
        initialSearch,
        initialDepartmentId,
        perPage: pagination.per_page,
    });

    return (
        <Main>
            <PageHeader
                title="No Contract Employees"
                description="Employees who have no contracts assigned."
                right={
                    <Link
                        href={contracts.url()}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" aria-hidden />
                        Back to contracts
                    </Link>
                }
            />

            <SearchBar
                value={searchInput}
                onChange={onSearchChange}
                placeholder="Search by name or employee number..."
                right={
                    <div className="flex items-center gap-2">
                        {department_tree && department_tree.length > 0 ? (
                            <DepartmentFilterControls
                                department_tree={department_tree}
                                department_tree_selected_id={department_tree_selected_id}
                                department_tree_selected_position_id={null}
                                onSelectDepartment={onDepartmentChange}
                                onSelectPosition={(_, depId) =>
                                    onDepartmentChange(depId)
                                }
                            />
                        ) : null}
                        {isSearching ? (
                            <Loader2
                                className="size-4 animate-spin text-muted-foreground"
                                aria-hidden
                            />
                        ) : null}
                    </div>
                }
            />

            {employees.length === 0 ? (
                <EmptyState
                    icon={<UserX className="size-10 text-muted-foreground/40" />}
                    title="No employees found"
                    description={
                        searchInput || initialDepartmentId
                            ? 'No employees match your filters.'
                            : 'All employees have at least one contract assigned.'
                    }
                />
            ) : (
                <div className="space-y-4">
                    <OrganizationDataTable minWidth="min-w-[600px]" compact>
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead>Employee</DataTableHead>
                                <DataTableHead>Hire date</DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {employees.map((emp) => (
                                <NoContractTableRow key={emp.id} emp={emp} />
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
