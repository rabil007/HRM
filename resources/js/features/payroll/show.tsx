import { useForm, usePage } from '@inertiajs/react';
import { Building2, Pencil } from 'lucide-react';
import { useMemo, useState } from 'react';
import { index as payrollIndex } from '@/actions/App/Http/Controllers/Payroll/PayrollController';
import { show, storeTimesheet } from '@/actions/App/Http/Controllers/Payroll/PayrollController';
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
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { CrewTimesheetFormSheet } from './components/crew-timesheet-form-sheet';
import { PayrollBoardSummaryBar } from './components/payroll-board-summary-bar';
import { PayrollCategoryBadge } from './components/payroll-category-badge';
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

function draftToFormData(draft: CrewTimesheetFormData): CrewTimesheetFormData {
    return {
        period_id: draft.period_id,
        employee_id: draft.employee_id,
        standby_from: draft.standby_from ?? '',
        standby_to: draft.standby_to ?? '',
        standby_days: draft.standby_days ?? '',
        onsite_from: draft.onsite_from ?? '',
        onsite_to: draft.onsite_to ?? '',
        onsite_days: draft.onsite_days ?? '',
        overtime_amount: draft.overtime_amount ?? '0',
        additional_amount: draft.additional_amount ?? '0',
        deduction_amount: draft.deduction_amount ?? '0',
        remarks: draft.remarks ?? '',
    };
}

export function PayrollShowContent({
    period,
    rows,
    pagination,
    board_summary,
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

    const [activeEmployeeId, setActiveEmployeeId] = useState<number | null>(
        () => timesheet_draft?.employee_id ?? null,
    );

    const form = useForm<CrewTimesheetFormData>(
        timesheet_draft
            ? draftToFormData(timesheet_draft)
            : emptyTimesheetForm(period.id, 0),
    );

    const mergedErrors = useMemo(
        () => ({ ...page.props.errors, ...form.errors }),
        [page.props.errors, form.errors],
    );

    const canSave =
        period.supports_timesheets &&
        Boolean(period.is_editable) &&
        (permissions.create || permissions.update);

    const validationErrorEmployeeId =
        hasTimesheetErrors(mergedErrors) && form.data.employee_id > 0 ? form.data.employee_id : null;

    const sheetEmployeeId = activeEmployeeId ?? validationErrorEmployeeId;
    const isSheetOpen = sheetEmployeeId !== null;

    const currentRow = useMemo(
        () =>
            sheetEmployeeId !== null
                ? rows.find((entry) => entry.employee.id === sheetEmployeeId) ?? null
                : null,
        [rows, sheetEmployeeId],
    );

    const handleSheetOpenChange = (open: boolean) => {
        if (!open) {
            setActiveEmployeeId(null);
        }
    };

    const handleEdit = (row: CrewPayrollRow) => {
        setActiveEmployeeId(row.employee.id);
        form.clearErrors();
        form.setData(rowToFormData(row));
    };

    const handleSubmit = () => {
        form.post(storeTimesheet.url(period.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => setActiveEmployeeId(null),
            onError: () => setActiveEmployeeId(form.data.employee_id),
        });
    };

    return (
        <Main>
            <DetailsHeader
                kicker="Payroll"
                title={
                    <span className="inline-flex flex-wrap items-center gap-3">
                        {period.name}
                        <PayrollCategoryBadge category={period.payroll_category} />
                        <Badge variant={period.is_editable ? 'secondary' : 'outline'}>
                            {period.status_label}
                        </Badge>
                    </span>
                }
                description={`${formatDisplayDate(period.start_date)} — ${formatDisplayDate(period.end_date)} · Payment ${formatDisplayDate(period.payment_date)}`}
                backHref={payrollIndex.url()}
                backLabel="All pay periods"
            />

            <PayrollBoardSummaryBar period={period} summary={board_summary} />

            <div className="mb-4">
                <SearchBar
                    value={list.searchInput}
                    onChange={list.onSearchChange}
                    placeholder={`Search ${period.payroll_category_label.toLowerCase()} employees...`}
                />
            </div>

            {!period.supports_timesheets ? (
                <div className="mb-4 flex items-start gap-3 rounded-2xl border border-violet-500/20 bg-violet-500/5 px-4 py-3.5 text-sm text-muted-foreground">
                    <Building2 className="mt-0.5 h-4 w-4 shrink-0 text-violet-500" />
                    <p>
                        Office payroll for this period will be generated from attendance in a later phase. Below are
                        employees with an active office contract for this run.
                    </p>
                </div>
            ) : null}

            {rows.length === 0 ? (
                <EmptyState
                    title={`No ${period.payroll_category_label.toLowerCase()} employees`}
                    description={`Only employees with an active ${period.payroll_category_label.toLowerCase()} contract appear on this pay run.`}
                />
            ) : period.supports_timesheets ? (
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
                                <TableRow key={row.employee.id} className={dataTableBodyRowClass()}>
                                    <TableCell className={dataTableCellPrimaryClass()}>
                                        <div className="flex items-center gap-3">
                                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-border/60 bg-muted/30 text-xs font-bold text-muted-foreground dark:border-white/10 dark:bg-white/5">
                                                {row.employee.name
                                                    .split(' ')
                                                    .filter(Boolean)
                                                    .slice(0, 2)
                                                    .map((part) => part[0]?.toUpperCase())
                                                    .join('') || '—'}
                                            </div>
                                            <span className="font-semibold">{row.employee.name}</span>
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {row.employee.employee_no ?? '—'}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {formatTimesheetDays(row.timesheet?.standby_days)}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {formatTimesheetDays(row.timesheet?.onsite_days)}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {formatTimesheetAmount(row.timesheet?.overtime_amount)}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {formatTimesheetAmount(row.timesheet?.additional_amount)}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {formatTimesheetAmount(row.timesheet?.deduction_amount)}
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        <Badge
                                            variant={row.is_filled ? 'default' : 'outline'}
                                            className={cn(
                                                !row.is_filled &&
                                                    'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-200',
                                            )}
                                        >
                                            {row.is_filled ? 'Filled' : 'Pending'}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className={dataTableActionsCellClass()}>
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
            ) : (
                <>
                    <OrganizationDataTable>
                        <TableHeader>
                            <DataTableHeaderRow>
                                <DataTableHead>Employee</DataTableHead>
                                <DataTableHead>Code</DataTableHead>
                            </DataTableHeaderRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row) => (
                                <TableRow key={row.employee.id} className={dataTableBodyRowClass()}>
                                    <TableCell className={dataTableCellPrimaryClass()}>
                                        <div className="flex items-center gap-3">
                                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-border/60 bg-muted/30 text-xs font-bold text-muted-foreground dark:border-white/10 dark:bg-white/5">
                                                {row.employee.name
                                                    .split(' ')
                                                    .filter(Boolean)
                                                    .slice(0, 2)
                                                    .map((part) => part[0]?.toUpperCase())
                                                    .join('') || '—'}
                                            </div>
                                            <span className="font-semibold">{row.employee.name}</span>
                                        </div>
                                    </TableCell>
                                    <TableCell className={dataTableCellClass()}>
                                        {row.employee.employee_no ?? '—'}
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

            {period.supports_timesheets ? (
                <CrewTimesheetFormSheet
                    open={isSheetOpen}
                    onOpenChange={handleSheetOpenChange}
                    row={currentRow}
                    canSave={canSave}
                    form={form}
                    errors={mergedErrors}
                    onSubmit={handleSubmit}
                />
            ) : null}
        </Main>
    );
}
