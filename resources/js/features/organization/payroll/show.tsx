import { useForm, usePage } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { index as payrollIndex } from '@/actions/App/Http/Controllers/Organization/PayrollController';
import { show, storeTimesheet } from '@/actions/App/Http/Controllers/Organization/PayrollController';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { DetailsHeader } from '@/components/details-header';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TableBody, TableCell, TableHeader, TableRow } from '@/components/ui/table';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { CrewTimesheetFormSheet } from './components/crew-timesheet-form-sheet';
import type {
    CrewPayrollRow,
    CrewTimesheetFormData,
    PayrollShowProps,
} from './types';
import { formatTimesheetAmount, formatTimesheetDays } from './types';

const TIMESHEET_FIELD_KEYS = [
    'period_id',
    'employee_id',
    'standby_from',
    'standby_to',
    'standby_days',
    'onsite_from',
    'onsite_to',
    'onsite_days',
    'overtime_amount',
    'additional_amount',
    'deduction_amount',
    'remarks',
] as const;

function hasTimesheetErrors(errors: Record<string, string | undefined>): boolean {
    return TIMESHEET_FIELD_KEYS.some((key) => Boolean(errors[key]));
}

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

export function PayrollShowContent({
    period,
    rows,
    pagination,
    search: initialSearch,
    permissions,
    timesheet_draft,
}: PayrollShowProps) {
    const page = usePage<{ errors: Record<string, string> }>();

    const list = useServerPaginationFilters({
        url: show.url(period.id),
        search: initialSearch,
        filters: {},
        pagination,
    });

    const [isSheetOpen, setIsSheetOpen] = useState(false);
    const [currentRow, setCurrentRow] = useState<CrewPayrollRow | null>(null);

    const form = useForm<CrewTimesheetFormData>(emptyTimesheetForm(period.id, 0));

    const mergedErrors = useMemo(
        () => ({ ...page.props.errors, ...form.errors }),
        [page.props.errors, form.errors],
    );

    const canSave = Boolean(period.is_editable) && (permissions.create || permissions.update);

    useEffect(() => {
        if (!timesheet_draft) {
            return;
        }

        form.setData({
            period_id: timesheet_draft.period_id,
            employee_id: timesheet_draft.employee_id,
            standby_from: timesheet_draft.standby_from ?? '',
            standby_to: timesheet_draft.standby_to ?? '',
            standby_days: timesheet_draft.standby_days ?? '',
            onsite_from: timesheet_draft.onsite_from ?? '',
            onsite_to: timesheet_draft.onsite_to ?? '',
            onsite_days: timesheet_draft.onsite_days ?? '',
            overtime_amount: timesheet_draft.overtime_amount ?? '0',
            additional_amount: timesheet_draft.additional_amount ?? '0',
            deduction_amount: timesheet_draft.deduction_amount ?? '0',
            remarks: timesheet_draft.remarks ?? '',
        });
        setIsSheetOpen(true);
        setCurrentRow(rows.find((entry) => entry.employee.id === timesheet_draft.employee_id) ?? null);
    }, [timesheet_draft, rows]);

    useEffect(() => {
        if (!hasTimesheetErrors(mergedErrors)) {
            return;
        }

        setIsSheetOpen(true);

        if (form.data.employee_id > 0) {
            const row = rows.find((entry) => entry.employee.id === form.data.employee_id) ?? null;
            setCurrentRow(row);
        }
    }, [mergedErrors, form.data.employee_id, rows]);

    const handleEdit = (row: CrewPayrollRow) => {
        setCurrentRow(row);
        form.clearErrors();
        form.setData(rowToFormData(row));
        setIsSheetOpen(true);
    };

    const handleSubmit = () => {
        form.post(storeTimesheet.url(period.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setIsSheetOpen(false),
            onError: () => setIsSheetOpen(true),
        });
    };

    return (
        <Main>
            <DetailsHeader
                kicker="Payroll"
                title={
                    <span className="inline-flex items-center gap-3">
                        {period.name} · Crew
                        <Badge variant={period.is_editable ? 'secondary' : 'outline'}>
                            {period.status_label}
                        </Badge>
                    </span>
                }
                description={`${period.start_date} — ${period.end_date} · Payment ${period.payment_date}`}
                backHref={payrollIndex.url()}
                backLabel="All pay periods"
            />

            <div className="mb-4">
                <SearchBar value={list.searchInput} onChange={list.onSearchChange} placeholder="Search crew..." />
            </div>

            {rows.length === 0 ? (
                <EmptyState
                    title="No crew employees"
                    description="Only employees with an active crew contract appear on this pay run."
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

            <CrewTimesheetFormSheet
                open={isSheetOpen}
                onOpenChange={setIsSheetOpen}
                row={currentRow}
                canSave={canSave}
                form={form}
                errors={mergedErrors}
                onSubmit={handleSubmit}
            />
        </Main>
    );
}
