import { router, useForm, usePage } from '@inertiajs/react';
import { Calculator, Pencil, RotateCcw, Upload, XCircle } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    approve,
    cancel,
    generatePayroll,
    index as payrollIndex,
    markPaid,
    revertToDraft,
    show,
    storeTimesheet,
} from '@/actions/App/Http/Controllers/Payroll/PayrollController';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { CrewTimesheetFormSheet } from './components/crew-timesheet-form-sheet';
import { CrewTimesheetImportDialog } from './components/crew-timesheet-import-dialog';
import { PayrollApproveDialog } from './components/payroll-approve-dialog';
import { PayrollBoardSummaryBar } from './components/payroll-board-summary-bar';
import { PayrollCancelDialog } from './components/payroll-cancel-dialog';
import { PayrollCategoryBadge } from './components/payroll-category-badge';
import { PayrollGenerateDialog } from './components/payroll-generate-dialog';
import { PayrollMarkPaidDialog } from './components/payroll-mark-paid-dialog';
import { PayrollPeriodDeliveryPanel } from './components/payroll-period-delivery-panel';
import { PayrollPeriodStatusBadge } from './components/payroll-period-status-badge';
import { OfficePayrollRecordsTable } from './components/office-payroll-records-table';
import { PayrollRecordsTable } from './components/payroll-records-table';
import { PayrollRevertToDraftDialog } from './components/payroll-revert-to-draft-dialog';
import { PayrollSkippedBanner } from './components/payroll-skipped-banner';
import { calculateInclusiveDays } from './lib/calculate-inclusive-days';
import type {
    CrewPayrollRecordListItem,
    CrewPayrollRow,
    CrewTimesheetFormData,
    OfficePayrollRecordListItem,
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

function resolveTimesheetDays(
    days: string | null | undefined,
    from: string | null | undefined,
    to: string | null | undefined,
): string {
    const stored = days ?? '';

    if (stored !== '') {
        return stored;
    }

    return calculateInclusiveDays(from ?? '', to ?? '');
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
        standby_days: resolveTimesheetDays(
            timesheet?.standby_days,
            timesheet?.standby_from,
            timesheet?.standby_to,
        ),
        onsite_from: timesheet?.onsite_from ?? '',
        onsite_to: timesheet?.onsite_to ?? '',
        onsite_days: resolveTimesheetDays(
            timesheet?.onsite_days,
            timesheet?.onsite_from,
            timesheet?.onsite_to,
        ),
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
        standby_days: resolveTimesheetDays(draft.standby_days, draft.standby_from, draft.standby_to),
        onsite_from: draft.onsite_from ?? '',
        onsite_to: draft.onsite_to ?? '',
        onsite_days: resolveTimesheetDays(draft.onsite_days, draft.onsite_from, draft.onsite_to),
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
    payroll_records,
    payroll_records_pagination,
    tab,
    generation_summary,
    search: initialSearch,
    permissions,
    payslip_summary,
    wps_preview,
    timesheet_draft,
}: PayrollShowProps) {
    const page = usePage<{ errors: Record<string, string> }>();
    const [isGenerateDialogOpen, setIsGenerateDialogOpen] = useState(false);
    const [isGenerating, setIsGenerating] = useState(false);
    const [isRevertDialogOpen, setIsRevertDialogOpen] = useState(false);
    const [isReverting, setIsReverting] = useState(false);
    const [isApproveDialogOpen, setIsApproveDialogOpen] = useState(false);
    const [isApproving, setIsApproving] = useState(false);
    const [isMarkPaidDialogOpen, setIsMarkPaidDialogOpen] = useState(false);
    const [isMarkingPaid, setIsMarkingPaid] = useState(false);
    const [isCancelDialogOpen, setIsCancelDialogOpen] = useState(false);
    const [isCancelling, setIsCancelling] = useState(false);
    const [isImportDialogOpen, setIsImportDialogOpen] = useState(false);

    const list = useServerPaginationFilters({
        url: show.url(period.id),
        search: initialSearch,
        filters: { tab },
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

    const handleTabChange = (value: string) => {
        router.get(
            show.url(period.id),
            { tab: value, search: initialSearch || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const handleGeneratePayroll = () => {
        setIsGenerating(true);
        router.post(
            generatePayroll.url(period.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsGenerating(false);
                    setIsGenerateDialogOpen(false);
                },
            },
        );
    };

    const handleRevertToDraft = () => {
        setIsReverting(true);
        router.post(
            revertToDraft.url(period.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsReverting(false);
                    setIsRevertDialogOpen(false);
                },
            },
        );
    };

    const handleApprove = () => {
        setIsApproving(true);
        router.post(
            approve.url(period.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsApproving(false);
                    setIsApproveDialogOpen(false);
                },
            },
        );
    };

    const handleMarkPaid = () => {
        setIsMarkingPaid(true);
        router.post(
            markPaid.url(period.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsMarkingPaid(false);
                    setIsMarkPaidDialogOpen(false);
                },
            },
        );
    };

    const handleCancel = () => {
        setIsCancelling(true);
        router.post(
            cancel.url(period.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsCancelling(false);
                    setIsCancelDialogOpen(false);
                },
            },
        );
    };

    const canGenerate = period.can_generate_payroll && permissions.generate_payroll;

    const canRevertToDraft = period.can_revert_to_draft && permissions.revert_to_draft;
    const canApprove = period.can_approve && permissions.approve;
    const canMarkPaid = period.can_mark_paid && permissions.mark_paid;
    const canCancelPeriod = period.can_cancel && permissions.cancel;

    const hasHeaderActions =
        canGenerate || canRevertToDraft || canApprove || canMarkPaid || canCancelPeriod;

    const recordsPagination = payroll_records_pagination;

    return (
        <Main>
            <DetailsHeader
                kicker="Payroll"
                title={
                    <span className="inline-flex flex-wrap items-center gap-3">
                        {period.name}
                        <PayrollCategoryBadge category={period.payroll_category} />
                        <PayrollPeriodStatusBadge
                            status={period.status}
                            label={period.status_label}
                        />
                    </span>
                }
                description={`${formatDisplayDate(period.start_date)} — ${formatDisplayDate(period.end_date)} · Payment ${formatDisplayDate(period.payment_date)}`}
                backHref={payrollIndex.url()}
                backLabel="Go back"
                actions={
                    hasHeaderActions ? (
                        <div className="flex flex-wrap items-center gap-2">
                            {canCancelPeriod ? (
                                <Button
                                    variant="outline"
                                    className="h-12 rounded-xl border-destructive/30 px-6 text-destructive bg-destructive/5 hover:bg-destructive/15 hover:text-destructive transition-all duration-300"
                                    onClick={() => setIsCancelDialogOpen(true)}
                                >
                                    <XCircle className="mr-2 h-4 w-4" />
                                    Cancel pay run
                                </Button>
                            ) : null}
                            {canRevertToDraft ? (
                                <Button
                                    variant="outline"
                                    className="h-12 rounded-xl px-6 bg-secondary/50 backdrop-blur-md border border-border/50 hover:bg-secondary/80 transition-all duration-300"
                                    onClick={() => setIsRevertDialogOpen(true)}
                                >
                                    <RotateCcw className="mr-2 h-4 w-4" />
                                    Revert to draft
                                </Button>
                            ) : null}
                            {canApprove ? (
                                <Button
                                    variant="outline"
                                    className="h-12 rounded-xl px-6 bg-secondary/50 backdrop-blur-md border border-border/50 hover:bg-secondary/80 transition-all duration-300"
                                    onClick={() => setIsApproveDialogOpen(true)}
                                >
                                    Approve pay run
                                </Button>
                            ) : null}
                            {canMarkPaid ? (
                                <Button
                                    className="h-12 rounded-xl px-6 bg-gradient-to-r from-emerald-500 to-emerald-400 hover:from-emerald-600 hover:to-emerald-500 text-white shadow-lg shadow-emerald-500/25 transition-all duration-300 hover:scale-105 active:scale-95"
                                    onClick={() => setIsMarkPaidDialogOpen(true)}
                                >
                                    Mark as paid
                                </Button>
                            ) : null}
                            {canGenerate ? (
                                <Button
                                    className="h-12 rounded-xl px-6 bg-gradient-to-r from-primary to-primary/80 hover:from-primary/90 hover:to-primary text-primary-foreground shadow-lg shadow-primary/25 transition-all duration-300 hover:scale-105 active:scale-95"
                                    onClick={() => setIsGenerateDialogOpen(true)}
                                >
                                    <Calculator className="mr-2 h-4 w-4" />
                                    Generate payroll
                                </Button>
                            ) : null}
                        </div>
                    ) : null
                }
            />

            <PayrollBoardSummaryBar period={period} summary={board_summary} />

            {period.supports_timesheets ? (
                <Tabs value={tab} onValueChange={handleTabChange} className="mb-4">
                    <TabsList className="mb-4 h-12 rounded-xl bg-muted/40 p-1 backdrop-blur-lg">
                        <TabsTrigger value="timesheets" className="h-full rounded-lg px-6 data-[state=active]:bg-background data-[state=active]:shadow-sm data-[state=active]:text-foreground transition-all duration-300">
                            Timesheets
                        </TabsTrigger>
                        <TabsTrigger value="payroll" className="h-full rounded-lg px-6 data-[state=active]:bg-background data-[state=active]:shadow-sm data-[state=active]:text-foreground transition-all duration-300">
                            Payroll
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="timesheets">
                        <div className="mb-4 flex flex-wrap items-center gap-3">
                            <div className="min-w-0 flex-1">
                                <SearchBar
                                    value={list.searchInput}
                                    onChange={list.onSearchChange}
                                    placeholder={`Search ${period.payroll_category_label.toLowerCase()} employees...`}
                                />
                            </div>
                            {permissions.import_timesheets && canSave ? (
                                <Button
                                    variant="outline"
                                    className="h-12 rounded-xl px-6"
                                    onClick={() => setIsImportDialogOpen(true)}
                                >
                                    <Upload className="mr-2 h-4 w-4" />
                                    Import Excel
                                </Button>
                            ) : null}
                        </div>
                        {renderTimesheetsTab()}
                    </TabsContent>

                    <TabsContent value="payroll">
                        <PayrollSkippedBanner
                            summary={generation_summary}
                            payrollCategory={period.payroll_category}
                        />
                        {renderPayrollTab()}
                    </TabsContent>
                </Tabs>
            ) : (
                <Tabs value={tab} onValueChange={handleTabChange} className="mb-4">
                    <TabsList className="mb-4 h-12 rounded-xl bg-muted/40 p-1 backdrop-blur-lg">
                        <TabsTrigger value="employees" className="h-full rounded-lg px-6 data-[state=active]:bg-background data-[state=active]:shadow-sm data-[state=active]:text-foreground transition-all duration-300">
                            Employees
                        </TabsTrigger>
                        <TabsTrigger value="payroll" className="h-full rounded-lg px-6 data-[state=active]:bg-background data-[state=active]:shadow-sm data-[state=active]:text-foreground transition-all duration-300">
                            Payroll
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="employees">
                        <div className="mb-4">
                            <SearchBar
                                value={list.searchInput}
                                onChange={list.onSearchChange}
                                placeholder={`Search ${period.payroll_category_label.toLowerCase()} employees...`}
                            />
                        </div>
                        {renderOfficeEmployeesTab()}
                    </TabsContent>

                    <TabsContent value="payroll">
                        <PayrollSkippedBanner
                            summary={generation_summary}
                            payrollCategory={period.payroll_category}
                        />
                        {renderPayrollTab()}
                    </TabsContent>
                </Tabs>
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

            {period.supports_timesheets ? (
                <CrewTimesheetImportDialog
                    open={isImportDialogOpen}
                    onOpenChange={setIsImportDialogOpen}
                    periodId={period.id}
                />
            ) : null}

            <PayrollGenerateDialog
                open={isGenerateDialogOpen}
                onOpenChange={setIsGenerateDialogOpen}
                onConfirm={handleGeneratePayroll}
                processing={isGenerating}
                payrollCategory={period.payroll_category}
            />

            <PayrollRevertToDraftDialog
                open={isRevertDialogOpen}
                onOpenChange={setIsRevertDialogOpen}
                onConfirm={handleRevertToDraft}
                processing={isReverting}
            />

            <PayrollApproveDialog
                open={isApproveDialogOpen}
                onOpenChange={setIsApproveDialogOpen}
                onConfirm={handleApprove}
                processing={isApproving}
            />

            <PayrollMarkPaidDialog
                open={isMarkPaidDialogOpen}
                onOpenChange={setIsMarkPaidDialogOpen}
                onConfirm={handleMarkPaid}
                processing={isMarkingPaid}
            />

            <PayrollCancelDialog
                open={isCancelDialogOpen}
                onOpenChange={setIsCancelDialogOpen}
                onConfirm={handleCancel}
                processing={isCancelling}
            />
        </Main>
    );

    function renderTimesheetsTab() {
        if (rows.length === 0) {
            return (
                <EmptyState
                    title={`No ${period.payroll_category_label.toLowerCase()} employees`}
                    description={`Only employees with an active ${period.payroll_category_label.toLowerCase()} contract appear on this pay run.`}
                />
            );
        }

        return (
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
                            <TableRow key={row.employee.id} className={cn(dataTableBodyRowClass(), "group hover:bg-muted/40 transition-colors duration-200")}>
                                <TableCell className={dataTableCellPrimaryClass()}>
                                    <div className="flex items-center gap-3">
                                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-border/60 bg-gradient-to-br from-primary/10 to-primary/30 text-xs font-bold text-primary dark:border-white/10 shadow-inner group-hover:scale-105 transition-transform">
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
        );
    }

    function renderPayrollTab() {
        const emptyDescription = period.supports_timesheets
            ? 'Generate payroll from entered timesheets to review gross and net amounts.'
            : 'Generate payroll from attendance records to review gross and net amounts.';

        if (payroll_records.length === 0) {
            return (
                <EmptyState
                    title="No payroll records yet"
                    description={emptyDescription}
                    action={
                        canGenerate ? (
                            <Button className="rounded-xl" onClick={() => setIsGenerateDialogOpen(true)}>
                                <Calculator className="mr-2 h-4 w-4" />
                                Generate payroll
                            </Button>
                        ) : undefined
                    }
                />
            );
        }

        const crewRecords = payroll_records.filter(
            (record): record is CrewPayrollRecordListItem => record.payroll_category === 'crew',
        );
        const officeRecords = payroll_records.filter(
            (record): record is OfficePayrollRecordListItem => record.payroll_category === 'office',
        );

        return (
            <>
                <PayrollPeriodDeliveryPanel
                    period={period}
                    payslip_summary={payslip_summary}
                    wps_preview={wps_preview}
                    permissions={permissions}
                />
                {period.supports_timesheets ? (
                    <PayrollRecordsTable
                        records={crewRecords}
                        canViewPayslips={permissions.payslips_view}
                    />
                ) : (
                    <OfficePayrollRecordsTable
                        records={officeRecords}
                        canViewPayslips={permissions.payslips_view}
                    />
                )}
                {recordsPagination ? (
                    <Pagination
                        currentPage={recordsPagination.current_page}
                        lastPage={recordsPagination.last_page}
                        perPage={recordsPagination.per_page}
                        total={recordsPagination.total}
                        from={recordsPagination.from}
                        to={recordsPagination.to}
                        onPageChange={(page) => {
                            router.get(
                                show.url(period.id),
                                { tab: 'payroll', records_page: page, search: initialSearch || undefined },
                                { preserveState: true, preserveScroll: true },
                            );
                        }}
                    />
                ) : null}
            </>
        );
    }

    function renderOfficeEmployeesTab() {
        if (rows.length === 0) {
            return (
                <EmptyState
                    title={`No ${period.payroll_category_label.toLowerCase()} employees`}
                    description={`Only employees with an active ${period.payroll_category_label.toLowerCase()} contract appear on this pay run.`}
                />
            );
        }

        return (
            <>
                <OrganizationDataTable>
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead>Employee</DataTableHead>
                            <DataTableHead>Code</DataTableHead>
                            <DataTableHead>Present</DataTableHead>
                            <DataTableHead>Absent</DataTableHead>
                            <DataTableHead>OT hours</DataTableHead>
                            <DataTableHead>Status</DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => (
                            <TableRow key={row.employee.id} className={cn(dataTableBodyRowClass(), "group hover:bg-muted/40 transition-colors duration-200")}>
                                <TableCell className={dataTableCellPrimaryClass()}>
                                    <div className="flex items-center gap-3">
                                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-border/60 bg-gradient-to-br from-primary/10 to-primary/30 text-xs font-bold text-primary dark:border-white/10 shadow-inner group-hover:scale-105 transition-transform">
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
                                    {formatTimesheetDays(
                                        row.attendance_summary
                                            ? String(row.attendance_summary.present_days)
                                            : null,
                                    )}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {formatTimesheetDays(
                                        row.attendance_summary
                                            ? String(row.attendance_summary.absent_days)
                                            : null,
                                    )}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {formatTimesheetDays(
                                        row.attendance_summary
                                            ? String(row.attendance_summary.overtime_hours)
                                            : null,
                                    )}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <Badge
                                        variant={row.is_filled ? 'default' : 'outline'}
                                        className={cn(
                                            !row.is_filled &&
                                                'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-200',
                                        )}
                                    >
                                        {row.is_filled ? 'Has attendance' : 'No attendance'}
                                    </Badge>
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
        );
    }
}
