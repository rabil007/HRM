import { Link, router, useForm } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { index as crewPayrollIndex } from '@/actions/App/Http/Controllers/Organization/CrewPayrollController';
import { storeTimesheet } from '@/actions/App/Http/Controllers/Organization/CrewPayrollController';
import { index as payrollPeriodsIndex } from '@/actions/App/Http/Controllers/Organization/PayrollPeriodController';
import { AppSelect, AppSelectItem } from '@/components/app-select';
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { CrewTimesheetFormSheet } from './components/crew-timesheet-form-sheet';
import type {
    CrewPayrollBoardProps,
    CrewPayrollRow,
    CrewTimesheetFormData,
} from './types';
import { formatTimesheetAmount, formatTimesheetDays } from './types';

function emptyTimesheetForm(periodId: number, employeeId: number): CrewTimesheetFormData {
    return {
        period_id: periodId,
        employee_id: employeeId,
        standby_from: '',
        standby_to: '',
        standby_days: '',
        onsite_from: '',
        onsite_to: '',
        onsite_days: '',
        overtime_amount: '0',
        additional_amount: '0',
        deduction_amount: '0',
        remarks: '',
    };
}

function rowToFormData(row: CrewPayrollRow): CrewTimesheetFormData {
    const timesheet = row.timesheet;

    return {
        period_id: row.period_id,
        employee_id: row.employee.id,
        standby_from: timesheet?.standby_from ?? '',
        standby_to: timesheet?.standby_to ?? '',
        standby_days: timesheet?.standby_days ?? '',
        onsite_from: timesheet?.onsite_from ?? '',
        onsite_to: timesheet?.onsite_to ?? '',
        onsite_days: timesheet?.onsite_days ?? '',
        overtime_amount: timesheet?.overtime_amount ?? '0',
        additional_amount: timesheet?.additional_amount ?? '0',
        deduction_amount: timesheet?.deduction_amount ?? '0',
        remarks: timesheet?.remarks ?? '',
    };
}

export function CrewPayrollContent({
    periods,
    selectedPeriod,
    rows,
    pagination,
    search: initialSearch,
    permissions,
}: CrewPayrollBoardProps) {
    const periodId = selectedPeriod?.id ?? null;

    const list = useServerPaginationFilters({
        url: crewPayrollIndex.url(),
        search: initialSearch,
        filters: { period_id: periodId },
        pagination,
    });

    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [currentRow, setCurrentRow] = useState<CrewPayrollRow | null>(null);

    const form = useForm<CrewTimesheetFormData>(
        emptyTimesheetForm(periodId ?? 0, 0),
    );

    const canSave =
        Boolean(selectedPeriod?.is_editable) &&
        (permissions.create || permissions.update);

    const handlePeriodChange = (value: string) => {
        router.get(
            crewPayrollIndex.url(),
            { period_id: value, search: initialSearch || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const handleEdit = (row: CrewPayrollRow) => {
        setCurrentRow(row);
        form.clearErrors();
        form.setData(rowToFormData(row));
        setIsSheetOpen(true);
    };

    const handleSubmit = () => {
        form.post(storeTimesheet.url(), {
            preserveScroll: true,
            onSuccess: () => setIsSheetOpen(false),
        });
    };

    return (
        <Main>
            <PageHeader
                title="Crew Payroll"
                description="Enter monthly worked days and salary inputs for crew employees."
                actions={
                    <Button variant="outline" className="rounded-xl" asChild>
                        <Link href={payrollPeriodsIndex.url()}>Manage periods</Link>
                    </Button>
                }
            />

            {periods.length === 0 ? (
                <EmptyState
                    title="No payroll periods"
                    description="Create a payroll period before entering crew timesheets."
                    action={
                        <Button className="rounded-xl" asChild>
                            <Link href={payrollPeriodsIndex.url()}>
                                <Plus className="mr-2 h-4 w-4" />
                                Create period
                            </Link>
                        </Button>
                    }
                />
            ) : (
                <>
                    <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div className="w-full max-w-xs space-y-2">
                            <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground/70">
                                Pay period
                            </p>
                            <AppSelect
                                value={periodId ? String(periodId) : undefined}
                                onValueChange={handlePeriodChange}
                                variant="card"
                            >
                                {periods.map((period) => (
                                    <AppSelectItem key={period.id} value={String(period.id)}>
                                        {period.name} ({period.status_label})
                                    </AppSelectItem>
                                ))}
                            </AppSelect>
                        </div>

                        {selectedPeriod ? (
                            <Badge variant={selectedPeriod.is_editable ? 'secondary' : 'outline'}>
                                {selectedPeriod.status_label}
                            </Badge>
                        ) : null}
                    </div>

                    <div className="mb-4">
                        <SearchBar value={list.searchInput} onChange={list.onSearchChange} placeholder="Search crew..." />
                    </div>

                    {rows.length === 0 ? (
                        <EmptyState
                            title="No crew employees"
                            description="Only employees with an active crew contract appear on this board."
                        />
                    ) : (
                        <>
                            <OrganizationDataTable>
                                <TableHeader>
                                    <DataTableHeaderRow>
                                        <DataTableHead>Employee</DataTableHead>
                                        <DataTableHead>Code</DataTableHead>
                                        <DataTableHead>Standby days</DataTableHead>
                                        <DataTableHead>Onsite days</DataTableHead>
                                        <DataTableHead>OT</DataTableHead>
                                        <DataTableHead>Additions</DataTableHead>
                                        <DataTableHead>Deductions</DataTableHead>
                                        <DataTableHead>Status</DataTableHead>
                                        <DataTableHead className="text-right">Actions</DataTableHead>
                                    </DataTableHeaderRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map((row) => (
                                        <TableRow key={row.employee.id} className={dataTableBodyRowClass}>
                                            <TableCell className={dataTableCellPrimaryClass}>
                                                {row.employee.name}
                                            </TableCell>
                                            <TableCell className={dataTableCellClass}>
                                                {row.employee.employee_no ?? '—'}
                                            </TableCell>
                                            <TableCell className={dataTableCellClass}>
                                                {formatTimesheetDays(row.timesheet?.standby_days)}
                                            </TableCell>
                                            <TableCell className={dataTableCellClass}>
                                                {formatTimesheetDays(row.timesheet?.onsite_days)}
                                            </TableCell>
                                            <TableCell className={dataTableCellClass}>
                                                {formatTimesheetAmount(row.timesheet?.overtime_amount)}
                                            </TableCell>
                                            <TableCell className={dataTableCellClass}>
                                                {formatTimesheetAmount(row.timesheet?.additional_amount)}
                                            </TableCell>
                                            <TableCell className={dataTableCellClass}>
                                                {formatTimesheetAmount(row.timesheet?.deduction_amount)}
                                            </TableCell>
                                            <TableCell className={dataTableCellClass}>
                                                <Badge variant={row.is_filled ? 'default' : 'outline'}>
                                                    {row.is_filled ? 'Filled' : 'Empty'}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className={dataTableActionsCellClass}>
                                                {canSave ? (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="rounded-lg"
                                                        onClick={() => handleEdit(row)}
                                                    >
                                                        <Pencil className="mr-2 h-4 w-4" />
                                                        {row.is_filled ? 'Edit' : 'Enter'}
                                                    </Button>
                                                ) : null}
                                            </TableCell>
                                        </TableRow>
                                    ))}
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
                </>
            )}

            <CrewTimesheetFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                row={currentRow}
                canSave={canSave}
                form={form}
                onSubmit={handleSubmit}
            />
        </Main>
    );
}
