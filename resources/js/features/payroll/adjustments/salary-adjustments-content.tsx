import { router, useForm } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import {
    approve,
    index as adjustmentsIndex,
    store,
    update,
} from '@/actions/App/Http/Controllers/Payroll/SalaryAdjustmentController';
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
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { TableRowActions } from '@/components/table-row-actions';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { formatTimesheetAmount } from '@/features/payroll/types';
import { SalaryAdjustmentDeleteDialog } from './components/salary-adjustment-delete-dialog';
import { SalaryAdjustmentFormSheet } from './components/salary-adjustment-form-sheet';
import { SalaryAdjustmentRejectDialog } from './components/salary-adjustment-reject-dialog';
import { SalaryAdjustmentStatusBadge } from './components/salary-adjustment-status-badge';
import type {
    SalaryAdjustment,
    SalaryAdjustmentEmployeeOption,
    SalaryAdjustmentFilters,
    SalaryAdjustmentPeriodOption,
    SalaryAdjustmentPermissions,
} from './types';
import {
    defaultSalaryAdjustmentFormData,
    salaryAdjustmentToFormData,
} from './types';
import type { PaginationMeta } from '@/types/pagination';

export function SalaryAdjustmentsContent({
    adjustments,
    pagination,
    search: initialSearch,
    filters: initialFilters,
    employees,
    periods,
    type_options,
    can,
}: {
    adjustments: SalaryAdjustment[];
    pagination: PaginationMeta;
    search: string;
    filters: SalaryAdjustmentFilters;
    employees: SalaryAdjustmentEmployeeOption[];
    periods: SalaryAdjustmentPeriodOption[];
    type_options: Array<{ value: string; label: string }>;
    status_options: Array<{ value: string; label: string }>;
    can: SalaryAdjustmentPermissions;
}) {
    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [currentAdjustment, setCurrentAdjustment] = useState<SalaryAdjustment | null>(null);
    const [rejectAdjustment, setRejectAdjustment] = useState<SalaryAdjustment | null>(null);
    const [deleteAdjustment, setDeleteAdjustment] = useState<SalaryAdjustment | null>(null);

    const list = useServerPaginationFilters({
        url: adjustmentsIndex.url(),
        search: initialSearch,
        filters: initialFilters,
        pagination,
    });

    const form = useForm(defaultSalaryAdjustmentFormData());

    const handleAdd = () => {
        setCurrentAdjustment(null);
        form.clearErrors();
        form.setData(defaultSalaryAdjustmentFormData());
        setIsSheetOpen(true);
    };

    const handleEdit = (adjustment: SalaryAdjustment) => {
        setCurrentAdjustment(adjustment);
        form.clearErrors();
        form.setData(salaryAdjustmentToFormData(adjustment));
        setIsSheetOpen(true);
    };

    const handleSubmit = () => {
        if (currentAdjustment) {
            form.put(update.url(currentAdjustment.id), {
                preserveScroll: true,
                onSuccess: () => setIsSheetOpen(false),
            });

            return;
        }

        form.post(store.url(), {
            preserveScroll: true,
            onSuccess: () => setIsSheetOpen(false),
        });
    };

    const handleApprove = (adjustment: SalaryAdjustment) => {
        router.put(approve.url(adjustment.id), {}, { preserveScroll: true });
    };

    return (
        <Main>
            <PageHeader
                title="Salary adjustments"
                description="Manage bonuses, deductions, and other payroll adjustments."
                actions={
                    can.create ? (
                        <Button className="h-12 rounded-xl px-6 shadow-lg shadow-primary/20" onClick={handleAdd}>
                            <Plus className="mr-2 h-4 w-4" />
                            New adjustment
                        </Button>
                    ) : undefined
                }
            />

            <div className="mb-4 flex flex-wrap items-center gap-3">
                <SearchBar
                    value={list.searchInput}
                    onChange={list.onSearchChange}
                    placeholder="Search employees or reasons..."
                    className="min-w-[240px] flex-1"
                />
                <div className="flex flex-wrap gap-2">
                    {(['', 'pending', 'approved', 'rejected'] as const).map((status) => (
                        <Button
                            key={status || 'all'}
                            variant={initialFilters.status === status ? 'default' : 'outline'}
                            className="h-10 rounded-xl"
                            onClick={() => list.applyFilters({ status })}
                        >
                            {status === '' ? 'All' : status.charAt(0).toUpperCase() + status.slice(1)}
                        </Button>
                    ))}
                </div>
            </div>

            {adjustments.length === 0 ? (
                <EmptyState
                    title="No salary adjustments"
                    description="Create an adjustment to track bonuses, deductions, or loans."
                    action={
                        can.create ? (
                            <Button className="rounded-xl" onClick={handleAdd}>
                                <Plus className="mr-2 h-4 w-4" />
                                New adjustment
                            </Button>
                        ) : undefined
                    }
                />
            ) : (
                <>
                    <OrganizationDataTable minWidth="min-w-[1100px]">
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead className="pl-5">Employee</DataTableHead>
                                <DataTableHead>Type</DataTableHead>
                                <DataTableHead>Amount</DataTableHead>
                                <DataTableHead>Period</DataTableHead>
                                <DataTableHead>Status</DataTableHead>
                                <DataTableHead className="text-right">Actions</DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {adjustments.map((adjustment) => {
                                const isPending = adjustment.status === 'pending';

                                return (
                                    <TableRow key={adjustment.id} className={dataTableBodyRowClass(false)}>
                                        <TableCell className={dataTableCellPrimaryClass()}>
                                            <div className="font-semibold">{adjustment.employee?.name ?? '—'}</div>
                                            <div className="text-xs text-muted-foreground">
                                                {adjustment.employee?.employee_no ?? '—'}
                                            </div>
                                        </TableCell>
                                        <TableCell className={dataTableCellClass()}>
                                            {adjustment.type_label}
                                        </TableCell>
                                        <TableCell className={dataTableCellClass()}>
                                            {formatTimesheetAmount(adjustment.amount)}
                                        </TableCell>
                                        <TableCell className={dataTableCellClass()}>
                                            {adjustment.period?.name ?? '—'}
                                        </TableCell>
                                        <TableCell className={dataTableCellClass()}>
                                            <SalaryAdjustmentStatusBadge
                                                status={adjustment.status}
                                                label={adjustment.status_label}
                                            />
                                        </TableCell>
                                        <TableCell className={dataTableActionsCellClass()}>
                                            <TableRowActions
                                                actions={[
                                                    {
                                                        label: 'Approve',
                                                        onClick: () => handleApprove(adjustment),
                                                        hidden: !(isPending && can.approve),
                                                    },
                                                    {
                                                        label: 'Reject',
                                                        onClick: () => setRejectAdjustment(adjustment),
                                                        hidden: !(isPending && can.approve),
                                                    },
                                                    {
                                                        label: 'Edit',
                                                        onClick: () => handleEdit(adjustment),
                                                        hidden: !(isPending && can.update),
                                                    },
                                                    {
                                                        label: 'Delete',
                                                        variant: 'danger',
                                                        onClick: () => setDeleteAdjustment(adjustment),
                                                        hidden: !(isPending && can.delete),
                                                    },
                                                ]}
                                            />
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </OrganizationDataTable>

                    <Pagination
                        currentPage={pagination.current_page}
                        lastPage={pagination.last_page}
                        perPage={pagination.per_page}
                        total={pagination.total}
                        from={pagination.from}
                        to={pagination.to}
                        onPageChange={list.onPageChange}
                    />
                </>
            )}

            <SalaryAdjustmentFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                adjustment={currentAdjustment}
                employees={employees}
                periods={periods}
                typeOptions={type_options}
                form={form}
                onSubmit={handleSubmit}
            />

            <SalaryAdjustmentRejectDialog
                open={rejectAdjustment !== null}
                onOpenChange={(open) => !open && setRejectAdjustment(null)}
                adjustment={rejectAdjustment}
            />

            <SalaryAdjustmentDeleteDialog
                open={deleteAdjustment !== null}
                onOpenChange={(open) => !open && setDeleteAdjustment(null)}
                adjustment={deleteAdjustment}
            />
        </Main>
    );
}
