import { router } from '@inertiajs/react';
import { History, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { OrganizationListPageShell } from '@/components/organization-list-page-shell';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { EmployeeRestoreDialog } from '@/features/organization/employees/components/employee-restore-dialog';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { formatDisplayDateTime } from '@/lib/format-date';
import { DESKTOP_OPERATIONAL_TABLE_CLASS } from '@/lib/mobile-operational-list';
import { employees as employeesIndex } from '@/routes/organization';
import { deleted as deletedEmployeesIndex } from '@/routes/organization/employees';
import deletedEmployeeRoutes from '@/routes/organization/employees/deleted';
import type { PaginationMeta } from '@/types/pagination';
import type { DeletedEmployee, EmployeePageCan } from './types';

function formatEmployeeStatus(status: string): string {
    return status
        .split('_')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

export function DeletedEmployeesContent({
    employees,
    pagination,
    search: initialSearch,
    can,
}: {
    employees: DeletedEmployee[];
    pagination: PaginationMeta;
    search: string;
    can: EmployeePageCan;
}) {
    const list = useServerPaginationFilters({
        url: deletedEmployeesIndex.url(),
        search: initialSearch,
        filters: {},
        pagination,
    });
    const [restoreEmployee, setRestoreEmployee] =
        useState<DeletedEmployee | null>(null);

    const confirmRestore = () => {
        if (!restoreEmployee) {
            return;
        }

        router.post(
            deletedEmployeeRoutes.restore.url(restoreEmployee.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => setRestoreEmployee(null),
            },
        );
    };

    return (
        <>
            <OrganizationListPageShell
                kicker="Employees"
                title="Deleted"
                description="Restore soft-deleted employees. Employee numbers stay reserved until restored."
                headerRight={
                    <Button
                        type="button"
                        variant="secondary"
                        className="h-12 rounded-xl glass-card px-5 hover:bg-accent"
                        onClick={() => router.visit(employeesIndex.url())}
                    >
                        Back to Employees
                    </Button>
                }
                search={{
                    placeholder:
                        'Search deleted employees by number, name, email, or phone...',
                    value: list.searchInput,
                    onChange: list.onSearchChange,
                }}
                pagination={
                    <Pagination
                        {...list.paginationProps}
                        label="deleted employees"
                    />
                }
            >
                {employees.length === 0 ? (
                    <EmptyState
                        icon={
                            <History className="mx-auto mb-3 h-10 w-10 text-muted-foreground/60" />
                        }
                        title="No deleted employees found"
                        description={
                            initialSearch
                                ? 'Try a different search term.'
                                : 'Deleted employees will appear here and can be restored later.'
                        }
                    />
                ) : (
                    <div className={DESKTOP_OPERATIONAL_TABLE_CLASS}>
                        <OrganizationDataTable minWidth="min-w-[1280px]">
                            <TableHeader>
                                <DataTableHeaderRow>
                                    <DataTableHead>Employee No.</DataTableHead>
                                    <DataTableHead>Name</DataTableHead>
                                    <DataTableHead>Branch</DataTableHead>
                                    <DataTableHead>Department</DataTableHead>
                                    <DataTableHead>Position</DataTableHead>
                                    <DataTableHead>Work email</DataTableHead>
                                    <DataTableHead>Phone</DataTableHead>
                                    <DataTableHead>
                                        Previous status
                                    </DataTableHead>
                                    <DataTableHead>Deleted</DataTableHead>
                                    <DataTableHead className="text-right">
                                        Actions
                                    </DataTableHead>
                                </DataTableHeaderRow>
                            </TableHeader>
                            <TableBody>
                                {employees.map((employee) => (
                                    <TableRow
                                        key={employee.id}
                                        className={dataTableBodyRowClass()}
                                    >
                                        <TableCell
                                            className={dataTableCellPrimaryClass()}
                                        >
                                            {employee.employee_no}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            {employee.name}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            {employee.branch?.name ?? '—'}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            {employee.department?.name ?? '—'}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            {employee.position?.title ?? '—'}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            {employee.work_email ?? '—'}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            {employee.phone ?? '—'}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            {formatEmployeeStatus(
                                                employee.status,
                                            )}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            {employee.deleted_at
                                                ? formatDisplayDateTime(
                                                      employee.deleted_at,
                                                  )
                                                : '—'}
                                        </TableCell>
                                        <TableCell
                                            className={dataTableActionsCellClass()}
                                        >
                                            {can.manage_deleted ? (
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                    size="sm"
                                                    className="h-9 rounded-lg"
                                                    onClick={() =>
                                                        setRestoreEmployee(
                                                            employee,
                                                        )
                                                    }
                                                >
                                                    <RotateCcw className="mr-2 h-4 w-4" />
                                                    Restore
                                                </Button>
                                            ) : null}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </OrganizationDataTable>
                    </div>
                )}
            </OrganizationListPageShell>

            <EmployeeRestoreDialog
                open={restoreEmployee !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setRestoreEmployee(null);
                    }
                }}
                employee={restoreEmployee}
                onConfirm={confirmRestore}
            />
        </>
    );
}
