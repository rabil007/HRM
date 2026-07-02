import { router } from '@inertiajs/react';
import {
    Calculator,
    AlertCircle,
    Ban,
    Building2,
    Calendar,
    CalendarDays,
    CheckCircle2,
    CheckSquare,
    CreditCard,
    Paperclip,
    RotateCcw,
    Upload,
    Users,
    XCircle,
} from 'lucide-react';
import React, { useEffect, useMemo, useState } from 'react';
import {
    approve,
    cancel,
    destroyPayrollRecord,
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
    dataTableBodyRowClass,
    dataTableCellClass,
} from '@/components/data-table';
import { DetailsHeader } from '@/components/details-header';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { DepartmentFilterControls } from '@/features/organization/employees/components/department-filter-controls';

import type { SalaryPaymentMethodValue } from '@/features/organization/employees/salary-payment-method';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import type { PaginationMeta } from '@/types/pagination';
import { CrewTimesheetImportDialog } from './components/crew-timesheet-import-dialog';
import { OfficePayrollRecordsTable } from './components/office-payroll-records-table';
import { OfficeSalaryInputsSheet } from './components/office-salary-inputs-sheet';
import { PayrollApproveDialog } from './components/payroll-approve-dialog';
import { PayrollCancelDialog } from './components/payroll-cancel-dialog';
import { PayrollCategoryBadge } from './components/payroll-category-badge';
import { PayrollEmployeeCell } from './components/payroll-employee-cell';
import { PayrollGenerateDialog } from './components/payroll-generate-dialog';
import { PayrollMarkPaidDialog } from './components/payroll-mark-paid-dialog';
import { PayrollPeriodDeliveryPanel } from './components/payroll-period-delivery-panel';
import { PayrollPeriodStatusBadge } from './components/payroll-period-status-badge';
import {
    PayrollRecordBankAccountCell,
    PayrollRecordPaymentMethodCell,
} from './components/payroll-record-display-cells';
import { PayrollRecordRemoveDialog } from './components/payroll-record-remove-dialog';
import { PayrollRecordsSummaryCards } from './components/payroll-records-summary-cards';
import { PayrollRecordsTable } from './components/payroll-records-table';
import { PayrollRevertToDraftDialog } from './components/payroll-revert-to-draft-dialog';
import { PayrollSkippedBanner } from './components/payroll-skipped-banner';
import { calculateInclusiveDays } from './lib/calculate-inclusive-days';
import {
    getPayrollBoardSelectionSummary,
    pruneExcludedIds,
} from './lib/payroll-board-selection';
import type {
    CrewPayrollRecordListItem,
    CrewPayrollRow,
    EmployeeStats,
    OfficePayrollRecordListItem,
    PayrollPeriod,
    PayrollRecordListItem,
    PayrollShowFilters,
    PayrollShowProps,
    SalaryInput,
} from './types';
import { formatTimesheetDays } from './types';

export function PayrollShowContent({
    period,
    rows,
    pagination,
    all_board_employee_ids,
    payroll_records,
    payroll_records_pagination,
    all_payroll_record_ids,
    payroll_records_summary,
    salary_inputs_by_employee,
    salary_input_type_options,
    generation_summary,
    search: initialSearch,
    tab: initialTab,
    filters: initialFilters,
    department_tree,
    department_tree_selected_id,
    department_tree_selected_position_id,
    permissions,
    payslip_summary,
    wps_preview,
    employee_stats,
}: PayrollShowProps) {
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
    const [salaryInputsRecord, setSalaryInputsRecord] =
        useState<PayrollRecordListItem | null>(null);
    const [excludedIds, setExcludedIds] = useState<Set<number>>(
        () => new Set(period.excluded_employee_ids ?? []),
    );
    const [removeRecord, setRemoveRecord] =
        useState<PayrollRecordListItem | null>(null);
    const [isRemovingRecord, setIsRemovingRecord] = useState(false);
    const [selectedWpsRecordIds, setSelectedWpsRecordIds] = useState<number[]>(
        () => all_payroll_record_ids,
    );
    const [rowDates, setRowDates] = useState<
        Record<number, { start: string; end: string }>
    >({});
    const [crewDates, setCrewDates] = useState<
        Record<
            number,
            {
                standby_from: string;
                standby_to: string;
                onsite_from: string;
                onsite_to: string;
            }
        >
    >({});

    const handleCrewDateChange = (
        employeeId: number,
        field: 'standby_from' | 'standby_to' | 'onsite_from' | 'onsite_to',
        val: string,
        initialTimesheet: any,
    ) => {
        setCrewDates((prev) => {
            const existing = prev[employeeId] ?? {
                standby_from: initialTimesheet?.standby_from ?? '',
                standby_to: initialTimesheet?.standby_to ?? '',
                onsite_from: initialTimesheet?.onsite_from ?? '',
                onsite_to: initialTimesheet?.onsite_to ?? '',
            };

            return {
                ...prev,
                [employeeId]: {
                    ...existing,
                    [field]: val,
                },
            };
        });
    };

    const handleSaveCrewTimesheet = (
        employeeId: number,
        initialTimesheet: any,
    ) => {
        const current = crewDates[employeeId];

        if (!current) {
            return;
        }

        const standby_days = calculateInclusiveDays(
            current.standby_from,
            current.standby_to,
        );
        const onsite_days = calculateInclusiveDays(
            current.onsite_from,
            current.onsite_to,
        );

        router.post(
            storeTimesheet.url(period.id),
            {
                period_id: period.id,
                employee_id: employeeId,
                standby_from: current.standby_from || null,
                standby_to: current.standby_to || null,
                standby_days: standby_days ? Number(standby_days) : null,
                onsite_from: current.onsite_from || null,
                onsite_to: current.onsite_to || null,
                onsite_days: onsite_days ? Number(onsite_days) : null,
                overtime_amount: initialTimesheet?.overtime_amount ?? 0,
                additional_amount: initialTimesheet?.additional_amount ?? 0,
                deduction_amount: initialTimesheet?.deduction_amount ?? 0,
                remarks: initialTimesheet?.remarks ?? null,
            },
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    };

    useEffect(() => {
        setSelectedWpsRecordIds(all_payroll_record_ids);
    }, [period.id, all_payroll_record_ids]);

    useEffect(() => {
        setExcludedIds((current) =>
            pruneExcludedIds(current, all_board_employee_ids),
        );
    }, [all_board_employee_ids]);

    const list = useServerPaginationFilters({
        url: show.url(period.id),
        search: initialSearch,
        filters: {
            tab: initialTab,
            department_id: initialFilters.department_id,
            position_id: initialFilters.position_id,
            employee_group: initialFilters.employee_group ?? '',
        },
        pagination,
    });

    const payrollFilters: PayrollShowFilters = {
        department_id: initialFilters.department_id ?? '',
        position_id: initialFilters.position_id ?? '',
        employee_group: initialFilters.employee_group ?? '',
    };

    const activeEmployeeGroup = payrollFilters.employee_group;

    const handleEmployeeGroupSelect = (
        employeeGroup: PayrollShowFilters['employee_group'],
    ) => {
        list.applyFilters({
            tab: initialTab,
            department_id: payrollFilters.department_id,
            position_id: payrollFilters.position_id,
            employee_group: employeeGroup,
            page: null,
        });
    };

    const departmentTreeSelectionCount =
        payrollFilters.department_id || payrollFilters.position_id ? 1 : 0;

    const handleDepartmentSelect = (id: number | null) => {
        list.applyFilters({
            tab: initialTab,
            department_id: id !== null ? String(id) : '',
            position_id: '',
            employee_group: activeEmployeeGroup,
        });
    };

    const handlePositionSelect = (
        positionId: number,
        departmentId: number,
    ) => {
        list.applyFilters({
            tab: initialTab,
            department_id: String(departmentId),
            position_id: String(positionId),
            employee_group: activeEmployeeGroup,
        });
    };

    const employeeSearchPlaceholder = `Search ${period.payroll_category_label.toLowerCase()} employees...`;

    const renderListToolbar = (extra?: React.ReactNode) => (
        <SearchBar
            value={list.searchInput}
            onChange={list.onSearchChange}
            placeholder={employeeSearchPlaceholder}
            className="mb-4"
            right={
                <div className="flex shrink-0 flex-wrap items-center gap-3">
                    <DepartmentFilterControls
                        department_tree={department_tree}
                        department_tree_selected_id={
                            department_tree_selected_id
                        }
                        department_tree_selected_position_id={
                            department_tree_selected_position_id
                        }
                        selectionCount={departmentTreeSelectionCount}
                        onSelectDepartment={handleDepartmentSelect}
                        onSelectPosition={handlePositionSelect}
                    />
                    {extra}
                </div>
            }
        />
    );

    const handleGeneratePayroll = () => {
        setIsGenerating(true);
        const employeeDatesPayload: Record<
            number,
            { start_date: string; end_date: string }
        > = {};
        Object.entries(rowDates).forEach(([empId, dates]) => {
            employeeDatesPayload[Number(empId)] = {
                start_date: dates.start,
                end_date: dates.end,
            };
        });

        router.post(
            generatePayroll.url(period.id),
            {
                excluded_employee_ids: Array.from(excludedIds),
                employee_dates: employeeDatesPayload,
            },
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
                onSuccess: () => {
                    setExcludedIds(new Set());
                    setSelectedWpsRecordIds([]);
                },
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

    const handleMarkPaid = (files?: File[] | File | null) => {
        setIsMarkingPaid(true);
        const payload: Record<string, any> = {};

        if (Array.isArray(files) && files.length > 0) {
            payload.payment_proofs = files;
        } else if (files instanceof File) {
            payload.payment_proof = files;
        }

        router.post(markPaid.url(period.id), payload, {
            preserveScroll: true,
            onFinish: () => {
                setIsMarkingPaid(false);
                setIsMarkPaidDialogOpen(false);
            },
        });
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

    const handleRemoveRecord = () => {
        if (removeRecord === null) {
            return;
        }

        setIsRemovingRecord(true);
        router.delete(
            destroyPayrollRecord.url({
                payrollPeriod: period.id,
                payrollRecord: removeRecord.id,
            }),
            {
                preserveScroll: true,
                onSuccess: () => {
                    setExcludedIds((current) => {
                        const next = new Set(current);
                        next.add(removeRecord.employee.id);

                        return next;
                    });
                },
                onFinish: () => {
                    setIsRemovingRecord(false);
                    setRemoveRecord(null);
                },
            },
        );
    };

    const activeSalaryInputs: SalaryInput[] =
        salaryInputsRecord !== null
            ? (salary_inputs_by_employee[
                  String(salaryInputsRecord.employee.id)
              ] ?? [])
            : [];

    const canGenerate =
        period.can_generate_payroll && permissions.generate_payroll;

    const canRevertToDraft =
        period.can_revert_to_draft && permissions.revert_to_draft;
    const canApprove = period.can_approve && permissions.approve;
    const canMarkPaid = period.can_mark_paid && permissions.mark_paid;
    const canCancelPeriod = period.can_cancel && permissions.cancel;

    const canManageSalaryInputs =
        period.can_generate_payroll &&
        (permissions.salary_inputs_create ||
            permissions.salary_inputs_update ||
            permissions.salary_inputs_delete);

    const canShowPayslipActions =
        permissions.payslips_view &&
        (period.status === 'approved' || period.status === 'paid');

    const canSelectForWpsExport =
        permissions.wps_export &&
        (period.status === 'approved' || period.status === 'paid');

    const wpsSelection = useMemo(() => {
        if (!canSelectForWpsExport) {
            return undefined;
        }

        const recordIds = payroll_records.map((record) => record.id);
        const allSelected =
            recordIds.length > 0 &&
            recordIds.every((id) => selectedWpsRecordIds.includes(id));
        const someSelected = selectedWpsRecordIds.length > 0 && !allSelected;

        return {
            selectedRecordIds: selectedWpsRecordIds,
            allSelected,
            someSelected,
            onToggleRecord: (recordId: number) => {
                setSelectedWpsRecordIds((current) =>
                    current.includes(recordId)
                        ? current.filter((id) => id !== recordId)
                        : [...current, recordId],
                );
            },
            onToggleAll: () => {
                setSelectedWpsRecordIds(allSelected ? [] : recordIds);
            },
        };
    }, [canSelectForWpsExport, payroll_records, selectedWpsRecordIds]);

    const isProcessingPayRun = period.status === 'processing';
    const hasPayrollRecords = period.payroll_records_count > 0;
    const headerPrimaryActionClass =
        'h-12 rounded-xl border-0 px-6 bg-gradient-to-r from-blue-600 to-indigo-500 text-white hover:from-blue-700 hover:to-indigo-600 hover:text-white shadow-lg shadow-blue-500/25 transition-all duration-300 hover:scale-105 active:scale-95';
    const headerSecondaryActionClass =
        'h-12 rounded-xl px-6 border border-border/50 bg-secondary/50 text-foreground backdrop-blur-md hover:bg-secondary/80 hover:text-foreground transition-all duration-300';

    const hasHeaderActions =
        canGenerate ||
        canRevertToDraft ||
        canApprove ||
        canMarkPaid ||
        canCancelPeriod ||
        Boolean(period.has_payment_proof);

    const recordsPagination = payroll_records_pagination;

    return (
        <Main>
            <DetailsHeader
                kicker="Payroll"
                title={
                    <span className="inline-flex flex-wrap items-center gap-3">
                        {period.name}
                        <PayrollCategoryBadge
                            category={period.payroll_category}
                        />
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
                            {period.has_payment_proof &&
                            period.payment_proofs &&
                            period.payment_proofs.length > 0 ? (
                                period.payment_proofs.map((proof, idx) => (
                                    <a
                                        key={proof.id ?? idx}
                                        href={proof.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex h-12 items-center gap-2 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 text-sm font-medium text-emerald-600 transition-all hover:bg-emerald-500/20 dark:text-emerald-400"
                                    >
                                        <Paperclip className="h-4 w-4" />
                                        {period.payment_proofs!.length > 1
                                            ? `Payment Proof #${idx + 1}`
                                            : 'Payment Proof'}
                                    </a>
                                ))
                            ) : period.has_payment_proof &&
                              period.payment_proof_url ? (
                                <a
                                    href={period.payment_proof_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex h-12 items-center gap-2 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-5 text-sm font-medium text-emerald-600 transition-all hover:bg-emerald-500/20 dark:text-emerald-400"
                                >
                                    <Paperclip className="h-4 w-4" />
                                    Payment Proof
                                </a>
                            ) : null}
                            {canCancelPeriod ? (
                                <Button
                                    variant="outline"
                                    className="h-12 rounded-xl border-destructive/30 bg-destructive/5 px-6 text-destructive transition-all duration-300 hover:bg-destructive/15 hover:text-destructive"
                                    onClick={() => setIsCancelDialogOpen(true)}
                                >
                                    <XCircle className="mr-2 h-4 w-4" />
                                    Cancel pay run
                                </Button>
                            ) : null}
                            {canRevertToDraft ? (
                                <Button
                                    variant="outline"
                                    className={headerSecondaryActionClass}
                                    onClick={() => setIsRevertDialogOpen(true)}
                                >
                                    <RotateCcw className="mr-2 h-4 w-4" />
                                    Revert to draft
                                </Button>
                            ) : null}
                            {canMarkPaid ? (
                                <Button
                                    className="h-12 rounded-xl bg-gradient-to-r from-emerald-500 to-emerald-400 px-6 text-white shadow-lg shadow-emerald-500/25 transition-all duration-300 hover:scale-105 hover:from-emerald-600 hover:to-emerald-500 active:scale-95"
                                    onClick={() =>
                                        setIsMarkPaidDialogOpen(true)
                                    }
                                >
                                    Mark as paid
                                </Button>
                            ) : null}
                            {canGenerate ? (
                                <Button
                                    variant={
                                        isProcessingPayRun ? 'outline' : undefined
                                    }
                                    className={
                                        isProcessingPayRun
                                            ? headerSecondaryActionClass
                                            : headerPrimaryActionClass
                                    }
                                    onClick={() =>
                                        setIsGenerateDialogOpen(true)
                                    }
                                >
                                    <Calculator className="mr-2 h-4 w-4" />
                                    {hasPayrollRecords
                                        ? 'Update payroll'
                                        : 'Generate payroll'}
                                </Button>
                            ) : null}
                            {canApprove ? (
                                <Button
                                    variant={
                                        isProcessingPayRun
                                            ? undefined
                                            : 'outline'
                                    }
                                    className={
                                        isProcessingPayRun
                                            ? headerPrimaryActionClass
                                            : headerSecondaryActionClass
                                    }
                                    onClick={() => setIsApproveDialogOpen(true)}
                                >
                                    <CheckCircle2 className="mr-2 h-4 w-4" />
                                    Approve pay run
                                </Button>
                            ) : null}
                        </div>
                    ) : null
                }
            />

            {/* ── Status Timeline ─────────────────────────── */}
            <PayrollStatusTimeline
                status={period.status}
                approver={period.approver}
            />

            {/* ── Section 1: Employees / Timesheets ──────── */}
            {period.status === 'draft' && (
                <section className="space-y-4">
                    <div className="flex items-center gap-3">
                        <div className="h-px flex-1 bg-border/60" />
                        <span className="text-[11px] font-bold tracking-widest text-muted-foreground/50 uppercase">
                            {period.supports_timesheets
                                ? 'Timesheets'
                                : 'Employees'}
                        </span>
                        <div className="h-px flex-1 bg-border/60" />
                    </div>

                    {period.supports_timesheets
                        ? renderListToolbar(
                              permissions.import_timesheets ? (
                                  <Button
                                      variant="outline"
                                      className="h-12 shrink-0 rounded-xl px-6"
                                      onClick={() =>
                                          setIsImportDialogOpen(true)
                                      }
                                  >
                                      <Upload className="mr-2 h-4 w-4" />
                                      Import Excel
                                  </Button>
                              ) : null,
                          )
                        : renderListToolbar()}

                    {period.supports_timesheets
                        ? renderTimesheetsTab()
                        : renderOfficeEmployeesTab()}
                </section>
            )}

            {/* ── Section 2: Payroll Records ──────────────── */}
            {period.status !== 'draft' && (
                <section className="space-y-4">
                    <div className="flex items-center gap-3">
                        <div className="h-px flex-1 bg-border/60" />
                        <span className="text-[11px] font-bold tracking-widest text-muted-foreground/50 uppercase">
                            Payroll Records
                        </span>
                        <div className="h-px flex-1 bg-border/60" />
                    </div>
                    <SearchBar
                        value={list.searchInput}
                        onChange={list.onSearchChange}
                        placeholder="Search payroll records..."
                        className="mb-4"
                        right={
                            <DepartmentFilterControls
                                department_tree={department_tree}
                                department_tree_selected_id={
                                    department_tree_selected_id
                                }
                                department_tree_selected_position_id={
                                    department_tree_selected_position_id
                                }
                                selectionCount={departmentTreeSelectionCount}
                                onSelectDepartment={handleDepartmentSelect}
                                onSelectPosition={handlePositionSelect}
                            />
                        }
                    />
                    <PayrollSkippedBanner
                        summary={generation_summary}
                        payrollCategory={period.payroll_category}
                    />
                    <PayrollPeriodDeliveryPanel
                        period={period}
                        payslip_summary={payslip_summary}
                        wps_preview={wps_preview}
                        permissions={permissions}
                        selectedWpsRecordIds={
                            canSelectForWpsExport ? selectedWpsRecordIds : null
                        }
                    />
                    {payroll_records_summary ? (
                        <PayrollRecordsSummaryCards
                            summary={payroll_records_summary}
                        />
                    ) : null}
                    {renderPayrollTab()}
                </section>
            )}

            {period.supports_timesheets ? (
                <CrewTimesheetImportDialog
                    open={isImportDialogOpen}
                    onOpenChange={setIsImportDialogOpen}
                    periodId={period.id}
                />
            ) : null}

            <OfficeSalaryInputsSheet
                open={salaryInputsRecord !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setSalaryInputsRecord(null);
                    }
                }}
                periodId={period.id}
                record={salaryInputsRecord as any}
                inputs={activeSalaryInputs}
                typeOptions={salary_input_type_options}
                canCreate={permissions.salary_inputs_create}
                canUpdate={permissions.salary_inputs_update}
                canDelete={permissions.salary_inputs_delete}
            />

            <PayrollGenerateDialog
                open={isGenerateDialogOpen}
                onOpenChange={setIsGenerateDialogOpen}
                onConfirm={handleGeneratePayroll}
                processing={isGenerating}
                payrollCategory={period.payroll_category}
                hasExistingRecords={hasPayrollRecords}
                excludedCount={excludedIds.size}
            />

            <PayrollRevertToDraftDialog
                open={isRevertDialogOpen}
                onOpenChange={setIsRevertDialogOpen}
                onConfirm={handleRevertToDraft}
                processing={isReverting}
                supportsTimesheets={period.supports_timesheets}
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

            <PayrollRecordRemoveDialog
                open={removeRecord !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setRemoveRecord(null);
                    }
                }}
                employeeName={removeRecord?.employee.name ?? null}
                onConfirm={handleRemoveRecord}
                processing={isRemovingRecord}
            />
        </Main>
    );

    function renderTimesheetsTab() {
        const allIds = all_board_employee_ids;
        const selection = getPayrollBoardSelectionSummary({
            pagination,
            allBoardEmployeeIds: allIds,
            excludedIds,
            rows,
        });

        const handleSelectAll = (checked: boolean | 'indeterminate') => {
            if (checked === true) {
                setExcludedIds(new Set());
            } else {
                setExcludedIds(new Set(allIds));
            }
        };

        const handleRowToggle = (
            employeeId: number,
            checked: boolean | 'indeterminate',
        ) => {
            setExcludedIds((prev) => {
                const next = new Set(prev);

                if (checked === true) {
                    next.delete(employeeId);
                } else {
                    next.add(employeeId);
                }

                return next;
            });
        };

        const includedCount = selection.includedCount;
        const hasPayRunEmployees = (employee_stats?.total ?? 0) > 0;
        const hasVisibleRows = rows.length > 0;

        return (
            <div className="space-y-6">
                {employee_stats !== null && (
                    <EmployeeAnalyticsCardsGrid
                        employee_stats={employee_stats}
                        activeEmployeeGroup={activeEmployeeGroup}
                        onEmployeeGroupSelect={handleEmployeeGroupSelect}
                    />
                )}

                {!hasPayRunEmployees ? (
                    <EmptyState
                        title={`No ${period.payroll_category_label.toLowerCase()} employees`}
                        description={`Only employees with an active ${period.payroll_category_label.toLowerCase()} contract appear on this pay run.`}
                    />
                ) : !hasVisibleRows ? (
                    <PayrollBoardFilteredEmptyState
                        activeEmployeeGroup={activeEmployeeGroup}
                        onShowAll={() => handleEmployeeGroupSelect('')}
                    />
                ) : (
                    <>
                {/* Selection info bar */}
                <div className="flex items-center justify-between rounded-xl border border-border/40 bg-muted/30 px-4 py-2.5 backdrop-blur-sm">
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <CheckSquare className="h-4 w-4 shrink-0 text-primary" />
                        <span>
                            <span className="font-semibold text-foreground">
                                {includedCount}
                            </span>{' '}
                            of{' '}
                            <span className="font-semibold text-foreground">
                                {selection.totalCount}
                            </span>{' '}
                            employees included
                        </span>
                        {selection.excludedCount > 0 && (
                            <Badge
                                variant="outline"
                                className="ml-1 border-amber-500/30 bg-amber-500/10 text-[10px] font-semibold text-amber-700 dark:text-amber-300"
                            >
                                {selection.excludedCount} excluded
                            </Badge>
                        )}
                    </div>
                    {selection.excludedCount > 0 && (
                        <button
                            type="button"
                            onClick={() => setExcludedIds(new Set())}
                            className="text-xs font-medium text-primary underline-offset-2 transition-colors hover:underline"
                        >
                            Include all
                        </button>
                    )}
                </div>

                <OrganizationDataTable>
                    <TableHeader>
                        {/* Group labels row */}
                        <tr className="border-b-0">
                            <th
                                colSpan={2}
                                className="h-7 border-b border-border/30"
                            />
                            <th
                                colSpan={1}
                                className="h-7 border-b border-border/30"
                            />
                            <th
                                colSpan={3}
                                className="h-7 border-x border-b border-primary/15 bg-primary/3 px-3 text-center text-[10px] font-bold tracking-[0.15em] text-primary/50 uppercase"
                            >
                                Daily Rates
                            </th>
                            <th
                                colSpan={2}
                                className="h-7 border-x border-b border-blue-500/15 bg-blue-500/3 px-3 text-center text-[10px] font-bold tracking-[0.15em] text-blue-600/60 uppercase dark:text-blue-400/60"
                            >
                                Days
                            </th>
                            <th
                                colSpan={2}
                                className="h-7 border-b border-border/30"
                            />
                        </tr>
                        <DataTableHeaderRow>
                            <DataTableHead className="w-10">
                                <Checkbox
                                    id="select-all-crew-employees"
                                    checked={selection.headerChecked}
                                    onCheckedChange={handleSelectAll}
                                    aria-label="Select all employees"
                                    className="rounded"
                                />
                            </DataTableHead>
                            <DataTableHead>Employee</DataTableHead>
                            <DataTableHead>Bank</DataTableHead>
                            <DataTableHead className="border-l border-primary/10 bg-primary/3 text-right">
                                Basic
                            </DataTableHead>
                            <DataTableHead className="bg-primary/3 text-right">
                                Suppl.
                            </DataTableHead>
                            <DataTableHead className="border-r border-primary/10 bg-primary/3 text-right">
                                Site
                            </DataTableHead>
                            <DataTableHead className="border-l border-blue-500/10 bg-blue-500/3">
                                Standby
                            </DataTableHead>
                            <DataTableHead className="border-r border-blue-500/10 bg-blue-500/3">
                                Onsite
                            </DataTableHead>
                            <DataTableHead>Payment</DataTableHead>
                            <DataTableHead>Status</DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map((row) => {
                            const isExcluded = excludedIds.has(row.employee.id);
                            const paymentMethod = (row.salary_payment_method ??
                                'bank_transfer') as SalaryPaymentMethodValue;
                            const contract = row.contract ?? null;

                            const currentCrewDates = crewDates[row.employee.id];
                            const standbyFrom =
                                currentCrewDates?.standby_from ??
                                row.timesheet?.standby_from ??
                                '';
                            const standbyTo =
                                currentCrewDates?.standby_to ??
                                row.timesheet?.standby_to ??
                                '';
                            const onsiteFrom =
                                currentCrewDates?.onsite_from ??
                                row.timesheet?.onsite_from ??
                                '';
                            const onsiteTo =
                                currentCrewDates?.onsite_to ??
                                row.timesheet?.onsite_to ??
                                '';

                            const standbyDays = currentCrewDates
                                ? calculateInclusiveDays(standbyFrom, standbyTo)
                                : (row.timesheet?.standby_days ??
                                  calculateInclusiveDays(
                                      standbyFrom,
                                      standbyTo,
                                  ));
                            const onsiteDays = currentCrewDates
                                ? calculateInclusiveDays(onsiteFrom, onsiteTo)
                                : (row.timesheet?.onsite_days ??
                                  calculateInclusiveDays(onsiteFrom, onsiteTo));

                            const isDirty = !!crewDates[row.employee.id];

                            return (
                                <TableRow
                                    key={row.employee.id}
                                    className={cn(
                                        dataTableBodyRowClass(),
                                        'group transition-all duration-200',
                                        isExcluded
                                            ? 'bg-muted/10 opacity-35 dark:bg-muted/5'
                                            : 'hover:bg-muted/30',
                                        isDirty &&
                                            !isExcluded &&
                                            'ring-1 ring-primary/20 ring-inset',
                                    )}
                                >
                                    {/* Checkbox */}
                                    <TableCell
                                        className={cn(
                                            dataTableCellClass(),
                                            'pl-4',
                                        )}
                                    >
                                        <Checkbox
                                            id={`crew-employee-${row.employee.id}`}
                                            checked={!isExcluded}
                                            onCheckedChange={(checked) =>
                                                handleRowToggle(
                                                    row.employee.id,
                                                    checked,
                                                )
                                            }
                                            aria-label={`Include ${row.employee.name}`}
                                            className="rounded"
                                        />
                                    </TableCell>

                                    <PayrollEmployeeCell
                                        employee={row.employee}
                                        isExcluded={isExcluded}
                                    />

                                    {/* Bank account */}
                                    <PayrollRecordBankAccountCell
                                        primary_account={
                                            row.primary_account ?? null
                                        }
                                        salary_payment_method={paymentMethod}
                                    />

                                    {/* Basic salary */}
                                    <TableCell
                                        className={cn(
                                            dataTableCellClass(),
                                            'border-l border-primary/8 bg-primary/2 text-right',
                                        )}
                                    >
                                        <div className="flex flex-col items-end gap-0.5">
                                            <SalaryCell
                                                value={contract?.basic_salary}
                                            />
                                            {contract?.basic_salary &&
                                                Number(contract.basic_salary) >
                                                    0 && (
                                                    <span className="text-[10px] text-muted-foreground/50">
                                                        / day
                                                    </span>
                                                )}
                                        </div>
                                    </TableCell>

                                    {/* Supplementary */}
                                    <TableCell
                                        className={cn(
                                            dataTableCellClass(),
                                            'bg-primary/2 text-right',
                                        )}
                                    >
                                        <SalaryCell
                                            value={
                                                contract?.supplementary_allowance
                                            }
                                        />
                                    </TableCell>

                                    {/* Site allowance */}
                                    <TableCell
                                        className={cn(
                                            dataTableCellClass(),
                                            'border-r border-primary/8 bg-primary/2 text-right',
                                        )}
                                    >
                                        <SalaryCell
                                            value={contract?.site_allowance}
                                        />
                                    </TableCell>

                                    {/* Standby dates */}
                                    <TableCell
                                        className={cn(
                                            dataTableCellClass(),
                                            'border-l border-blue-500/8 bg-blue-500/2',
                                        )}
                                    >
                                        <div className="flex flex-col gap-2">
                                            <div className="flex items-center gap-1">
                                                <Input
                                                    type="date"
                                                    value={standbyFrom}
                                                    onChange={(e) =>
                                                        handleCrewDateChange(
                                                            row.employee.id,
                                                            'standby_from',
                                                            e.target.value,
                                                            row.timesheet,
                                                        )
                                                    }
                                                    onBlur={() =>
                                                        handleSaveCrewTimesheet(
                                                            row.employee.id,
                                                            row.timesheet,
                                                        )
                                                    }
                                                    className="h-7 w-[130px] rounded-md border-border/50 bg-background/60 px-1.5 font-mono text-[11px] shadow-none transition-colors focus:bg-background [&::-webkit-calendar-picker-indicator]:cursor-pointer [&::-webkit-calendar-picker-indicator]:opacity-50 hover:[&::-webkit-calendar-picker-indicator]:opacity-90 [&::-webkit-calendar-picker-indicator]:dark:invert"
                                                />
                                                <span className="shrink-0 text-[10px] font-bold text-muted-foreground/40">
                                                    →
                                                </span>
                                                <Input
                                                    type="date"
                                                    value={standbyTo}
                                                    onChange={(e) =>
                                                        handleCrewDateChange(
                                                            row.employee.id,
                                                            'standby_to',
                                                            e.target.value,
                                                            row.timesheet,
                                                        )
                                                    }
                                                    onBlur={() =>
                                                        handleSaveCrewTimesheet(
                                                            row.employee.id,
                                                            row.timesheet,
                                                        )
                                                    }
                                                    className="h-7 w-[130px] rounded-md border-border/50 bg-background/60 px-1.5 font-mono text-[11px] shadow-none transition-colors focus:bg-background [&::-webkit-calendar-picker-indicator]:cursor-pointer [&::-webkit-calendar-picker-indicator]:opacity-50 hover:[&::-webkit-calendar-picker-indicator]:opacity-90 [&::-webkit-calendar-picker-indicator]:dark:invert"
                                                />
                                            </div>
                                            <Badge
                                                variant="secondary"
                                                className={cn(
                                                    'inline-flex w-fit items-center gap-1 rounded-md px-2 py-0.5 text-[10px] font-bold tabular-nums transition-colors',
                                                    standbyDays &&
                                                        Number(standbyDays) > 0
                                                        ? 'border-blue-500/20 bg-blue-500/10 text-blue-700 dark:text-blue-300'
                                                        : 'border-dashed border-border/60 bg-transparent text-muted-foreground/50',
                                                )}
                                            >
                                                {standbyDays &&
                                                Number(standbyDays) > 0 ? (
                                                    <>
                                                        {formatTimesheetDays(
                                                            standbyDays,
                                                        )}{' '}
                                                        days
                                                    </>
                                                ) : (
                                                    <>No dates set</>
                                                )}
                                            </Badge>
                                        </div>
                                    </TableCell>

                                    {/* Onsite dates */}
                                    <TableCell
                                        className={cn(
                                            dataTableCellClass(),
                                            'border-r border-blue-500/8 bg-blue-500/2',
                                        )}
                                    >
                                        <div className="flex flex-col gap-2">
                                            <div className="flex items-center gap-1">
                                                <Input
                                                    type="date"
                                                    value={onsiteFrom}
                                                    onChange={(e) =>
                                                        handleCrewDateChange(
                                                            row.employee.id,
                                                            'onsite_from',
                                                            e.target.value,
                                                            row.timesheet,
                                                        )
                                                    }
                                                    onBlur={() =>
                                                        handleSaveCrewTimesheet(
                                                            row.employee.id,
                                                            row.timesheet,
                                                        )
                                                    }
                                                    className="h-7 w-[130px] rounded-md border-border/50 bg-background/60 px-1.5 font-mono text-[11px] shadow-none transition-colors focus:bg-background [&::-webkit-calendar-picker-indicator]:cursor-pointer [&::-webkit-calendar-picker-indicator]:opacity-50 hover:[&::-webkit-calendar-picker-indicator]:opacity-90 [&::-webkit-calendar-picker-indicator]:dark:invert"
                                                />
                                                <span className="shrink-0 text-[10px] font-bold text-muted-foreground/40">
                                                    →
                                                </span>
                                                <Input
                                                    type="date"
                                                    value={onsiteTo}
                                                    onChange={(e) =>
                                                        handleCrewDateChange(
                                                            row.employee.id,
                                                            'onsite_to',
                                                            e.target.value,
                                                            row.timesheet,
                                                        )
                                                    }
                                                    onBlur={() =>
                                                        handleSaveCrewTimesheet(
                                                            row.employee.id,
                                                            row.timesheet,
                                                        )
                                                    }
                                                    className="h-7 w-[130px] rounded-md border-border/50 bg-background/60 px-1.5 font-mono text-[11px] shadow-none transition-colors focus:bg-background [&::-webkit-calendar-picker-indicator]:cursor-pointer [&::-webkit-calendar-picker-indicator]:opacity-50 hover:[&::-webkit-calendar-picker-indicator]:opacity-90 [&::-webkit-calendar-picker-indicator]:dark:invert"
                                                />
                                            </div>
                                            <Badge
                                                variant="secondary"
                                                className={cn(
                                                    'inline-flex w-fit items-center gap-1 rounded-md px-2 py-0.5 text-[10px] font-bold tabular-nums transition-colors',
                                                    onsiteDays &&
                                                        Number(onsiteDays) > 0
                                                        ? 'border-emerald-500/20 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                                        : 'border-dashed border-border/60 bg-transparent text-muted-foreground/50',
                                                )}
                                            >
                                                {onsiteDays &&
                                                Number(onsiteDays) > 0 ? (
                                                    <>
                                                        {formatTimesheetDays(
                                                            onsiteDays,
                                                        )}{' '}
                                                        days
                                                    </>
                                                ) : (
                                                    <>No dates set</>
                                                )}
                                            </Badge>
                                        </div>
                                    </TableCell>

                                    {/* Payment method */}
                                    <PayrollRecordPaymentMethodCell
                                        method={paymentMethod}
                                        label={
                                            row.salary_payment_method_label ??
                                            'Bank transfer'
                                        }
                                    />

                                    {/* Status */}
                                    <TableCell className={dataTableCellClass()}>
                                        <div className="flex flex-col items-start gap-1.5">
                                            <Badge
                                                variant={
                                                    row.is_filled
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                className={cn(
                                                    'text-[11px] font-semibold',
                                                    row.is_filled
                                                        ? 'border-emerald-500/30 bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
                                                        : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
                                                )}
                                            >
                                                {row.is_filled
                                                    ? '✓ Filled'
                                                    : 'Pending'}
                                            </Badge>
                                            {isDirty && (
                                                <span className="inline-flex items-center gap-1 text-[10px] font-medium text-primary/70">
                                                    <span className="inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-primary/60" />
                                                    Unsaved
                                                </span>
                                            )}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </OrganizationDataTable>

                <Pagination {...list.paginationProps} label="employees" />
                    </>
                )}
            </div>
        );
    }

    function renderPayrollTab() {
        const emptyDescription = period.supports_timesheets
            ? 'Generate payroll from entered timesheets to review gross and net amounts.'
            : 'Generate payroll to review full monthly salary and leave usage for this period.';

        if (payroll_records.length === 0) {
            return (
                <EmptyState
                    title="No payroll records yet"
                    description={emptyDescription}
                    action={
                        canGenerate ? (
                            <Button
                                className="rounded-xl"
                                onClick={() => setIsGenerateDialogOpen(true)}
                            >
                                <Calculator className="mr-2 h-4 w-4" />
                                Generate payroll
                            </Button>
                        ) : undefined
                    }
                />
            );
        }

        const crewRecords = payroll_records.filter(
            (record): record is CrewPayrollRecordListItem =>
                record.payroll_category === 'crew',
        );
        const officeRecords = payroll_records.filter(
            (record): record is OfficePayrollRecordListItem =>
                record.payroll_category === 'office',
        );

        return (
            <>
                {period.supports_timesheets ? (
                    <PayrollRecordsTable
                        records={crewRecords}
                        salaryInputsByEmployee={salary_inputs_by_employee}
                        canViewPayslips={permissions.payslips_view}
                        canShowPayslipActions={canShowPayslipActions}
                        canManageSalaryInputs={canManageSalaryInputs}
                        canRemove={canGenerate}
                        wpsSelection={wpsSelection}
                        onManageSalaryInputs={setSalaryInputsRecord}
                        onRemove={setRemoveRecord}
                    />
                ) : (
                    <OfficePayrollRecordsTable
                        records={officeRecords}
                        salaryInputsByEmployee={salary_inputs_by_employee}
                        canViewPayslips={permissions.payslips_view}
                        canShowPayslipActions={canShowPayslipActions}
                        canManageSalaryInputs={canManageSalaryInputs}
                        canRemove={canGenerate}
                        wpsSelection={wpsSelection}
                        onManageSalaryInputs={setSalaryInputsRecord}
                        onRemove={setRemoveRecord}
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
                                {
                                    tab: 'payroll',
                                    records_page: page,
                                    search: initialSearch || undefined,
                                },
                                { preserveState: true, preserveScroll: true },
                            );
                        }}
                    />
                ) : null}
            </>
        );
    }

    function renderOfficeEmployeesTab() {
        return (
            <OfficeEmployeesTabContent
                period={period}
                rows={rows}
                paginationProps={list.paginationProps}
                allBoardEmployeeIds={all_board_employee_ids}
                employee_stats={employee_stats}
                activeEmployeeGroup={activeEmployeeGroup}
                onEmployeeGroupSelect={handleEmployeeGroupSelect}
                excludedIds={excludedIds}
                setExcludedIds={setExcludedIds}
                rowDates={rowDates}
                setRowDates={setRowDates}
            />
        );
    }
}

// ─── Payroll Status Timeline ───────────────────────────────────────────────────

const PAYROLL_FLOW = [
    {
        status: 'draft',
        label: 'Draft',
        description: 'Pay run created',
    },
    {
        status: 'processing',
        label: 'Processing',
        description: 'Payroll generated',
    },
    {
        status: 'approved',
        label: 'Approved',
        description: 'Pay run approved',
    },
    {
        status: 'paid',
        label: 'Paid',
        description: 'Salaries disbursed',
    },
] as const;

function PayrollStatusTimeline({
    status,
    approver,
}: {
    status: string;
    approver: { id: number; name: string } | null;
}) {
    const isCancelled = status === 'cancelled';
    const currentIndex = PAYROLL_FLOW.findIndex((s) => s.status === status);

    if (isCancelled) {
        return (
            <div className="relative mb-6 overflow-hidden rounded-2xl border border-destructive/20 bg-gradient-to-r from-destructive/5 via-destructive/3 to-background p-4">
                <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_left,_var(--tw-gradient-stops))] from-destructive/10 via-transparent to-transparent" />
                <div className="relative flex items-center gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-destructive/30 bg-destructive/10 text-destructive shadow-inner">
                        <Ban className="h-5 w-5" />
                    </div>
                    <div>
                        <p className="text-sm font-bold text-destructive">
                            Pay Run Cancelled
                        </p>
                        <p className="text-xs text-muted-foreground/70">
                            This payroll period has been cancelled and cannot be
                            processed.
                        </p>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="relative mb-6 overflow-hidden rounded-2xl border border-border/40 bg-gradient-to-r from-muted/20 via-background to-background p-5 shadow-sm">
            {/* subtle background shimmer */}
            <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_left,_var(--tw-gradient-stops))] from-primary/5 via-transparent to-transparent" />

            <div className="relative">
                <div className="flex items-start justify-between gap-2">
                    {PAYROLL_FLOW.map((step, index) => {
                        const isCompleted = index < currentIndex;
                        const isActive = index === currentIndex;
                        const isFuture = index > currentIndex;
                        const isLast = index === PAYROLL_FLOW.length - 1;

                        return (
                            <React.Fragment key={step.status}>
                                {/* Step node */}
                                <div className="flex min-w-0 flex-1 flex-col items-center gap-2">
                                    {/* Circle */}
                                    <div
                                        className={cn(
                                            'relative flex h-10 w-10 shrink-0 items-center justify-center rounded-full border-2 transition-all duration-500',
                                            isCompleted &&
                                                'border-emerald-500 bg-emerald-500 text-white shadow-lg shadow-emerald-500/30',
                                            isActive &&
                                                'scale-110 border-primary bg-primary text-primary-foreground shadow-lg shadow-primary/40',
                                            isFuture &&
                                                'border-border/40 bg-muted/30 text-muted-foreground/40',
                                        )}
                                    >
                                        {isCompleted ? (
                                            <CheckCircle2 className="h-5 w-5" />
                                        ) : isActive ? (
                                            <>
                                                {/* Pulse ring for active */}
                                                <span className="absolute inset-0 animate-ping rounded-full bg-primary/30 duration-1000" />
                                                <span className="relative h-2.5 w-2.5 rounded-full bg-primary-foreground" />
                                            </>
                                        ) : (
                                            <span className="h-2 w-2 rounded-full bg-current" />
                                        )}
                                    </div>

                                    {/* Labels */}
                                    <div className="text-center">
                                        <p
                                            className={cn(
                                                'text-xs font-bold transition-colors duration-300',
                                                isCompleted &&
                                                    'text-emerald-600 dark:text-emerald-400',
                                                isActive && 'text-primary',
                                                isFuture &&
                                                    'text-muted-foreground/40',
                                            )}
                                        >
                                            {step.label}
                                        </p>
                                        <p
                                            className={cn(
                                                'mt-0.5 text-[10px] transition-colors duration-300',
                                                isActive
                                                    ? 'text-muted-foreground'
                                                    : 'text-muted-foreground/40',
                                            )}
                                        >
                                            {step.description}
                                        </p>
                                        {/* Approver badge */}
                                        {step.status === 'approved' &&
                                        isCompleted &&
                                        approver ? (
                                            <p className="mt-1 text-[10px] font-semibold text-emerald-600 dark:text-emerald-400">
                                                by {approver.name}
                                            </p>
                                        ) : null}
                                    </div>
                                </div>

                                {/* Connector line */}
                                {!isLast && (
                                    <div className="relative mt-5 h-0.5 flex-1 overflow-hidden rounded-full bg-border/30">
                                        <div
                                            className={cn(
                                                'h-full rounded-full transition-all duration-700',
                                                isCompleted
                                                    ? 'w-full bg-emerald-500'
                                                    : 'w-0',
                                            )}
                                        />
                                    </div>
                                )}
                            </React.Fragment>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

// ─── Analytics Cards ────────────────────────────────────────────────────────────

const employeeGroupLabels: Record<
    Exclude<PayrollShowFilters['employee_group'], ''>,
    string
> = {
    with_bank_account: 'Bank Account Set',
    cash_payment: 'Cash Payment',
    missing_bank_account: 'Missing Bank Account',
};

function PayrollBoardFilteredEmptyState({
    activeEmployeeGroup,
    onShowAll,
}: {
    activeEmployeeGroup: PayrollShowFilters['employee_group'];
    onShowAll: () => void;
}) {
    const filterLabel =
        activeEmployeeGroup !== ''
            ? employeeGroupLabels[activeEmployeeGroup]
            : 'this filter';

    return (
        <EmptyState
            title="No employees in this group"
            description={`No employees match "${filterLabel}". Select Total Employees or try another category.`}
            action={
                <Button
                    type="button"
                    variant="outline"
                    className="rounded-xl"
                    onClick={onShowAll}
                >
                    Show all employees
                </Button>
            }
        />
    );
}

function EmployeeAnalyticsCardsGrid({
    employee_stats,
    activeEmployeeGroup,
    onEmployeeGroupSelect,
}: {
    employee_stats: EmployeeStats;
    activeEmployeeGroup: PayrollShowFilters['employee_group'];
    onEmployeeGroupSelect: (
        employeeGroup: PayrollShowFilters['employee_group'],
    ) => void;
}) {
    return (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <EmployeeAnalyticsCard
                title="Total Employees"
                value={employee_stats.total}
                subtitle="Active on this pay run"
                icon={Users}
                variant="total"
                isSelected={activeEmployeeGroup === ''}
                onClick={() => onEmployeeGroupSelect('')}
            />
            <EmployeeAnalyticsCard
                title="Bank Account Set"
                value={employee_stats.with_bank_account}
                subtitle="Ready for salary transfer"
                icon={CreditCard}
                variant="success"
                isSelected={activeEmployeeGroup === 'with_bank_account'}
                onClick={() => onEmployeeGroupSelect('with_bank_account')}
            />
            <EmployeeAnalyticsCard
                title="Cash Payment"
                value={employee_stats.cash_payment_count}
                subtitle="Paid by C3, Ansari, or cash"
                icon={Building2}
                variant={
                    employee_stats.cash_payment_count > 0
                        ? 'warning'
                        : 'success'
                }
                isSelected={activeEmployeeGroup === 'cash_payment'}
                onClick={() => onEmployeeGroupSelect('cash_payment')}
            />
            <EmployeeAnalyticsCard
                title="Missing Bank Account"
                value={employee_stats.missing_bank_account}
                subtitle={
                    employee_stats.missing_bank_account > 0
                        ? 'Bank-transfer employees only — action required before WPS'
                        : 'All bank-transfer employees configured'
                }
                icon={
                    employee_stats.missing_bank_account > 0
                        ? AlertCircle
                        : Building2
                }
                variant={
                    employee_stats.missing_bank_account > 0
                        ? 'warning'
                        : 'success'
                }
                isSelected={activeEmployeeGroup === 'missing_bank_account'}
                onClick={() => onEmployeeGroupSelect('missing_bank_account')}
            />
        </div>
    );
}

function EmployeeAnalyticsCard({
    title,
    value,
    subtitle,
    icon: Icon,
    variant,
    isSelected = false,
    onClick,
}: {
    title: string;
    value: number;
    subtitle: string;
    icon: React.ElementType;
    variant: 'total' | 'success' | 'warning';
    isSelected?: boolean;
    onClick?: () => void;
}) {
    const styles = {
        total: {
            card: 'border-primary/20 bg-gradient-to-br from-primary/5 via-background to-background hover:border-primary/40 hover:shadow-primary/10',
            icon: 'bg-primary/10 border-primary/20 text-primary',
            value: 'text-primary',
            dot: 'bg-primary',
        },
        success: {
            card: 'border-emerald-500/20 bg-gradient-to-br from-emerald-500/5 via-background to-background hover:border-emerald-500/40 hover:shadow-emerald-500/10',
            icon: 'bg-emerald-500/10 border-emerald-500/20 text-emerald-600 dark:text-emerald-400',
            value: 'text-emerald-600 dark:text-emerald-400',
            dot: 'bg-emerald-500',
        },
        warning: {
            card: 'border-amber-500/20 bg-gradient-to-br from-amber-500/5 via-background to-background hover:border-amber-500/40 hover:shadow-amber-500/10',
            icon: 'bg-amber-500/10 border-amber-500/20 text-amber-600 dark:text-amber-400',
            value: 'text-amber-600 dark:text-amber-400',
            dot: 'bg-amber-500',
        },
    }[variant];

    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'group relative w-full overflow-hidden rounded-2xl border p-5 text-left transition-all duration-300 hover:-translate-y-0.5 hover:shadow-xl focus-visible:ring-2 focus-visible:ring-primary/40 focus-visible:outline-none',
                styles.card,
                isSelected && 'ring-2 ring-primary/50 shadow-lg',
            )}
        >
            {/* Subtle background glow */}
            <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-white/10 via-transparent to-transparent opacity-40 dark:from-white/5" />

            <div className="relative z-10 flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                    <p className="text-[11px] font-bold tracking-[0.16em] text-muted-foreground/60 uppercase">
                        {title}
                    </p>
                    <p
                        className={cn(
                            'mt-2 text-3xl font-extrabold tracking-tight tabular-nums',
                            styles.value,
                        )}
                    >
                        {value.toLocaleString()}
                    </p>
                    <p className="mt-1.5 flex items-center gap-1.5 text-xs text-muted-foreground/70">
                        <span
                            className={cn(
                                'inline-block h-1.5 w-1.5 rounded-full',
                                styles.dot,
                            )}
                        />
                        {subtitle}
                    </p>
                </div>
                <div
                    className={cn(
                        'flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border shadow-inner transition-transform duration-300 group-hover:scale-110',
                        styles.icon,
                    )}
                >
                    <Icon className="h-5 w-5" />
                </div>
            </div>
        </button>
    );
}

// ─── Office Employees Tab Content ─────────────────────────────────────────────

function SalaryCell({ value }: { value: string | null | undefined }) {
    if (!value || Number(value) === 0) {
        return <span className="text-xs text-muted-foreground/40">—</span>;
    }

    return (
        <span className="font-medium tabular-nums">
            {Number(value).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2,
            })}
        </span>
    );
}

function OfficeEmployeesTabContent({
    period,
    rows,
    paginationProps,
    allBoardEmployeeIds,
    employee_stats,
    activeEmployeeGroup,
    onEmployeeGroupSelect,
    excludedIds,
    setExcludedIds,
    rowDates,
    setRowDates,
}: {
    period: PayrollPeriod;
    rows: CrewPayrollRow[];
    paginationProps: {
        currentPage: number;
        lastPage: number;
        from: number | null;
        to: number | null;
        total: number;
        perPage: number;
        onPerPageChange: (perPage: number) => void;
        onPageChange: (page: number) => void;
    };
    allBoardEmployeeIds: number[];
    employee_stats: EmployeeStats | null;
    activeEmployeeGroup: PayrollShowFilters['employee_group'];
    onEmployeeGroupSelect: (
        employeeGroup: PayrollShowFilters['employee_group'],
    ) => void;
    excludedIds: Set<number>;
    setExcludedIds: React.Dispatch<React.SetStateAction<Set<number>>>;
    rowDates: Record<number, { start: string; end: string }>;
    setRowDates: React.Dispatch<
        React.SetStateAction<Record<number, { start: string; end: string }>>
    >;
}) {
    const handleStartDateChange = (employeeId: number, val: string) => {
        setRowDates((prev) => ({
            ...prev,
            [employeeId]: {
                start: val,
                end: prev[employeeId]?.end ?? period.end_date,
            },
        }));
    };

    const handleEndDateChange = (employeeId: number, val: string) => {
        setRowDates((prev) => ({
            ...prev,
            [employeeId]: {
                start: prev[employeeId]?.start ?? period.start_date,
                end: val,
            },
        }));
    };

    const selection = getPayrollBoardSelectionSummary({
        pagination: {
            current_page: paginationProps.currentPage,
            last_page: paginationProps.lastPage,
            per_page: paginationProps.perPage,
            total: paginationProps.total,
            from: paginationProps.from,
            to: paginationProps.to,
        },
        allBoardEmployeeIds,
        excludedIds,
        rows,
    });

    const handleSelectAll = (checked: boolean | 'indeterminate') => {
        if (checked === true) {
            setExcludedIds(new Set());
        } else {
            setExcludedIds(new Set(allBoardEmployeeIds));
        }
    };

    const handleRowToggle = (
        employeeId: number,
        checked: boolean | 'indeterminate',
    ) => {
        setExcludedIds((prev) => {
            const next = new Set(prev);

            if (checked === true) {
                next.delete(employeeId);
            } else {
                next.add(employeeId);
            }

            return next;
        });
    };

    const includedCount = selection.includedCount;
    const hasPayRunEmployees = (employee_stats?.total ?? 0) > 0;
    const hasVisibleRows = rows.length > 0;

    return (
        <div className="space-y-6">
            {employee_stats !== null && (
                <EmployeeAnalyticsCardsGrid
                    employee_stats={employee_stats}
                    activeEmployeeGroup={activeEmployeeGroup}
                    onEmployeeGroupSelect={onEmployeeGroupSelect}
                />
            )}

            {!hasPayRunEmployees ? (
                <EmptyState
                    title={`No ${period.payroll_category_label.toLowerCase()} employees`}
                    description={`Only employees with an active ${period.payroll_category_label.toLowerCase()} contract appear on this pay run.`}
                />
            ) : !hasVisibleRows ? (
                <PayrollBoardFilteredEmptyState
                    activeEmployeeGroup={activeEmployeeGroup}
                    onShowAll={() => onEmployeeGroupSelect('')}
                />
            ) : (
                <>
            {/* Selection info bar */}
            <div className="flex items-center justify-between rounded-xl border border-border/40 bg-muted/30 px-4 py-2.5 backdrop-blur-sm">
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <CheckSquare className="h-4 w-4 shrink-0 text-primary" />
                    <span>
                        <span className="font-semibold text-foreground">
                            {includedCount}
                        </span>{' '}
                        of{' '}
                        <span className="font-semibold text-foreground">
                            {selection.totalCount}
                        </span>{' '}
                        employees included
                    </span>
                    {selection.excludedCount > 0 && (
                        <Badge
                            variant="outline"
                            className="ml-1 border-amber-500/30 bg-amber-500/10 text-[10px] font-semibold text-amber-700 dark:text-amber-300"
                        >
                            {selection.excludedCount} excluded
                        </Badge>
                    )}
                </div>
                {selection.excludedCount > 0 && (
                    <button
                        type="button"
                        onClick={() => setExcludedIds(new Set())}
                        className="text-xs font-medium text-primary underline-offset-2 transition-colors hover:underline"
                    >
                        Include all
                    </button>
                )}
            </div>

            {/* Table */}
            <OrganizationDataTable>
                <TableHeader>
                    <DataTableHeaderRow>
                        {/* Select-all checkbox */}
                        <DataTableHead className="w-10">
                            <Checkbox
                                id="select-all-employees"
                                checked={selection.headerChecked}
                                onCheckedChange={handleSelectAll}
                                aria-label="Select all employees"
                                className="rounded"
                            />
                        </DataTableHead>
                        <DataTableHead>Employee</DataTableHead>
                        <DataTableHead>Bank account</DataTableHead>
                        {/* Salary columns */}
                        <DataTableHead>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span className="inline-flex cursor-default items-center gap-1.5">
                                        <Calculator className="h-3 w-3 text-primary/60" />
                                        Basic Salary
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent>
                                    From current contract
                                </TooltipContent>
                            </Tooltip>
                        </DataTableHead>
                        <DataTableHead>Housing Allow.</DataTableHead>
                        <DataTableHead>Transport Allow.</DataTableHead>
                        <DataTableHead>Other Allow.</DataTableHead>
                        <DataTableHead>Payment</DataTableHead>
                        <DataTableHead>
                            <span className="inline-flex cursor-default items-center gap-1.5">
                                <Calendar className="h-3 w-3 text-primary/60" />
                                Period (Start — End)
                            </span>
                        </DataTableHead>
                        <DataTableHead>
                            <span className="inline-flex cursor-default items-center gap-1.5">
                                <CalendarDays className="h-3 w-3 text-primary/60" />
                                Total Days
                            </span>
                        </DataTableHead>
                    </DataTableHeaderRow>
                </TableHeader>
                <TableBody>
                    {rows.map((row) => {
                        const isExcluded = excludedIds.has(row.employee.id);
                        const paymentMethod = (row.salary_payment_method ??
                            'bank_transfer') as SalaryPaymentMethodValue;
                        const contract = row.contract ?? null;
                        const startDate =
                            rowDates[row.employee.id]?.start ??
                            period.start_date;
                        const endDate =
                            rowDates[row.employee.id]?.end ?? period.end_date;
                        const totalDays = calculateInclusiveDays(
                            startDate,
                            endDate,
                        );

                        return (
                            <TableRow
                                key={row.employee.id}
                                className={cn(
                                    dataTableBodyRowClass(),
                                    'group transition-all duration-200',
                                    isExcluded
                                        ? 'bg-muted/20 opacity-40 dark:bg-muted/10'
                                        : 'hover:bg-muted/40',
                                )}
                            >
                                {/* Checkbox */}
                                <TableCell className={dataTableCellClass()}>
                                    <Checkbox
                                        id={`employee-${row.employee.id}`}
                                        checked={!isExcluded}
                                        onCheckedChange={(checked) =>
                                            handleRowToggle(
                                                row.employee.id,
                                                checked,
                                            )
                                        }
                                        aria-label={`Include ${row.employee.name}`}
                                        className="rounded"
                                    />
                                </TableCell>

                                <PayrollEmployeeCell
                                    employee={row.employee}
                                    isExcluded={isExcluded}
                                />

                                <PayrollRecordBankAccountCell
                                    primary_account={
                                        row.primary_account ?? null
                                    }
                                    salary_payment_method={paymentMethod}
                                />

                                {/* Basic salary */}
                                <TableCell
                                    className={cn(
                                        dataTableCellClass(),
                                        'text-right',
                                    )}
                                >
                                    <SalaryCell
                                        value={contract?.basic_salary}
                                    />
                                </TableCell>

                                {/* Housing allowance */}
                                <TableCell
                                    className={cn(
                                        dataTableCellClass(),
                                        'text-right',
                                    )}
                                >
                                    <SalaryCell
                                        value={contract?.housing_allowance}
                                    />
                                </TableCell>

                                {/* Transport allowance */}
                                <TableCell
                                    className={cn(
                                        dataTableCellClass(),
                                        'text-right',
                                    )}
                                >
                                    <SalaryCell
                                        value={contract?.transport_allowance}
                                    />
                                </TableCell>

                                {/* Other allowances */}
                                <TableCell
                                    className={cn(
                                        dataTableCellClass(),
                                        'text-right',
                                    )}
                                >
                                    <SalaryCell
                                        value={contract?.other_allowances}
                                    />
                                </TableCell>

                                <PayrollRecordPaymentMethodCell
                                    method={paymentMethod}
                                    label={
                                        row.salary_payment_method_label ??
                                        'Bank transfer'
                                    }
                                />

                                {/* Period dates */}
                                <TableCell className={dataTableCellClass()}>
                                    <div className="flex min-w-[310px] items-center gap-2">
                                        <Input
                                            type="date"
                                            value={startDate}
                                            onChange={(e) =>
                                                handleStartDateChange(
                                                    row.employee.id,
                                                    e.target.value,
                                                )
                                            }
                                            className="h-8 w-[142px] rounded-lg border-border/60 bg-background/50 px-2 font-mono text-xs transition-colors focus:bg-background [&::-webkit-calendar-picker-indicator]:cursor-pointer [&::-webkit-calendar-picker-indicator]:opacity-60 hover:[&::-webkit-calendar-picker-indicator]:opacity-100 [&::-webkit-calendar-picker-indicator]:dark:invert"
                                        />
                                        <span className="text-xs font-bold text-muted-foreground/50">
                                            —
                                        </span>
                                        <Input
                                            type="date"
                                            value={endDate}
                                            onChange={(e) =>
                                                handleEndDateChange(
                                                    row.employee.id,
                                                    e.target.value,
                                                )
                                            }
                                            className="h-8 w-[142px] rounded-lg border-border/60 bg-background/50 px-2 font-mono text-xs transition-colors focus:bg-background [&::-webkit-calendar-picker-indicator]:cursor-pointer [&::-webkit-calendar-picker-indicator]:opacity-60 hover:[&::-webkit-calendar-picker-indicator]:opacity-100 [&::-webkit-calendar-picker-indicator]:dark:invert"
                                        />
                                    </div>
                                </TableCell>

                                {/* Total days */}
                                <TableCell className={dataTableCellClass()}>
                                    <Badge
                                        variant="secondary"
                                        className={cn(
                                            'inline-flex items-center gap-1 rounded-lg border px-2.5 py-1 text-xs font-semibold tabular-nums transition-colors',
                                            totalDays && Number(totalDays) > 0
                                                ? 'border-emerald-500/25 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                                : 'border-amber-500/25 bg-amber-500/10 text-amber-700 dark:text-amber-300',
                                        )}
                                    >
                                        {formatTimesheetDays(totalDays)} days
                                    </Badge>
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </OrganizationDataTable>

            <Pagination {...paginationProps} label="employees" />
                </>
            )}
        </div>
    );
}
