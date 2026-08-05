import { router } from '@inertiajs/react';
import {
    AlertCircle,
    Calculator,
    CheckCircle2,
    Download,
    Eraser,
    Filter,
    Paperclip,
    RotateCcw,
    Ship,
    Upload,
    XCircle,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    approve,
    cancel,
    destroyPayrollRecord,
    exportPayroll,
    generatePayroll,
    index as payrollIndex,
    markPaid,
    revertToApproved,
    revertToDraft,
    revertToProcessing,
    show,
} from '@/actions/App/Http/Controllers/Payroll/PayrollController';
import PrepareCrewTimesheetTimelineController from '@/actions/App/Http/Controllers/Payroll/PrepareCrewTimesheetTimelineController';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { SearchBar } from '@/components/search-bar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { DepartmentFilterControls } from '@/features/organization/employees/components/department-filter-controls';
import { formatDisplayDate } from '@/lib/format-date';
import { show as crewTimelineShow } from '@/routes/payroll/crew-timeline';
import { clearManualImport } from '@/routes/payroll/crew-timesheets';
import { ClearCrewTimesheetsDialog } from './components/clear-crew-timesheets-dialog';
import { CrewMovementPeriodsDialog } from './components/crew-movement-periods-dialog';
import { CrewSalaryStructureToggle } from './components/crew-salary-structure-toggle';
import { CrewTimesheetImportDialog } from './components/crew-timesheet-import-dialog';
import { CrewTimesheetsBoard } from './components/crew-timesheets-board';
import { OfficeEmployeesTabContent } from './components/office-employees-tab-content';
import { OfficeSalaryInputsSheet } from './components/office-salary-inputs-sheet';
import { PayrollApproveDialog } from './components/payroll-approve-dialog';
import { PayrollCancelDialog } from './components/payroll-cancel-dialog';
import { PayrollCategoryBadge } from './components/payroll-category-badge';
import { PayrollCreationSourceBadge } from './components/payroll-creation-source-badge';
import { PayrollGenerateDialog } from './components/payroll-generate-dialog';
import { PayrollMarkPaidDialog } from './components/payroll-mark-paid-dialog';
import { PayrollPeriodDeliveryPanel } from './components/payroll-period-delivery-panel';
import { PayrollPeriodStatusBadge } from './components/payroll-period-status-badge';
import { PayrollRecordRemoveDialog } from './components/payroll-record-remove-dialog';
import { PayrollRecordsBoard } from './components/payroll-records-board';
import { PayrollRecordsSummaryCards } from './components/payroll-records-summary-cards';
import { PayrollReprepareTimelineDialog } from './components/payroll-reprepare-timeline-dialog';
import { PayrollRevertToApprovedDialog } from './components/payroll-revert-to-approved-dialog';
import { PayrollRevertToDraftDialog } from './components/payroll-revert-to-draft-dialog';
import { PayrollRevertToProcessingDialog } from './components/payroll-revert-to-processing-dialog';
import { PayrollShowFiltersSheet } from './components/payroll-show-filters-sheet';
import { PayrollSkippedBanner } from './components/payroll-skipped-banner';
import { PayrollStatusTimeline } from './components/payroll-status-timeline';
import { CrewTimelineStatusBadge } from './crew-timeline/crew-timeline-status-badge';
import { useCrewTimesheetFinancialAutosave } from './hooks/use-crew-timesheet-financial-autosave';
import { usePayslipGenerationPoll } from './hooks/use-payslip-generation-poll';
import type { MovementCategoryGroup } from './lib/crew-movement-period-drafts';
import { pruneExcludedIds } from './lib/payroll-board-selection';
import type {
    CrewPayrollRow,
    PayrollPeriodStatus,
    PayrollRecordListItem,
    PayrollShowFilters,
    PayrollShowProps,
    SalaryInput,
} from './types';
import { usePayrollShowFilters } from './use-payroll-show-filters';

export function PayrollShowContent({
    period,
    rows,
    pagination,
    all_board_employee_ids,
    payroll_records,
    payroll_records_pagination,
    payroll_records_monthly,
    payroll_records_monthly_pagination,
    all_payroll_record_ids,
    payroll_records_summary,
    salary_inputs_by_employee,
    salary_input_type_options,
    generation_summary,
    search: initialSearch,
    filters: initialFilters,
    company_visa_types,
    department_tree,
    department_tree_selected_id,
    department_tree_selected_position_id,
    permissions,
    payslip_summary,
    wps_preview,
    employee_stats,
    crew_timeline_preparation = null,
    clearable_timesheet_count = 0,
    movement_master_options = null,
}: PayrollShowProps) {
    const [isGenerateDialogOpen, setIsGenerateDialogOpen] = useState(false);
    const [isGenerating, setIsGenerating] = useState(false);
    const [isRevertDialogOpen, setIsRevertDialogOpen] = useState(false);
    const [isReverting, setIsReverting] = useState(false);
    const [isRevertToApprovedDialogOpen, setIsRevertToApprovedDialogOpen] =
        useState(false);
    const [isRevertingToApproved, setIsRevertingToApproved] = useState(false);
    const [isRevertToProcessingDialogOpen, setIsRevertToProcessingDialogOpen] =
        useState(false);
    const [isRevertingToProcessing, setIsRevertingToProcessing] =
        useState(false);
    const [isApproveDialogOpen, setIsApproveDialogOpen] = useState(false);
    const [isApproving, setIsApproving] = useState(false);
    const [isMarkPaidDialogOpen, setIsMarkPaidDialogOpen] = useState(false);
    const [isMarkingPaid, setIsMarkingPaid] = useState(false);
    const [markPaidDateError, setMarkPaidDateError] = useState<
        string | undefined
    >(undefined);
    const [isCancelDialogOpen, setIsCancelDialogOpen] = useState(false);
    const [isCancelling, setIsCancelling] = useState(false);
    const [isImportDialogOpen, setIsImportDialogOpen] = useState(false);
    const [movementPeriodsTarget, setMovementPeriodsTarget] = useState<{
        row: CrewPayrollRow;
        categoryGroup: MovementCategoryGroup;
    } | null>(null);
    const [isClearTimesheetsDialogOpen, setIsClearTimesheetsDialogOpen] =
        useState(false);
    const [isClearingTimesheets, setIsClearingTimesheets] = useState(false);
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const rowsRef = useRef(rows);

    useEffect(() => {
        rowsRef.current = rows;
    }, [rows]);

    const {
        crewTimesheetDrafts,
        savingTimesheetEmployeeIds,
        financialAutosaveErrors,
        handleCrewTimesheetChange,
        retryFinancialAutosave,
        flushPendingFinancialSave,
        beginClearTimesheets,
        endClearTimesheets,
        clearEmployeeDraft,
    } = useCrewTimesheetFinancialAutosave({
        periodId: period.id,
        resolveTimesheet: useCallback(
            (employeeId: number) =>
                rowsRef.current.find((row) => row.employee.id === employeeId)
                    ?.timesheet ?? null,
            [],
        ),
    });

    const handleConfirmClearTimesheets = useCallback(async () => {
        setIsClearingTimesheets(true);

        await beginClearTimesheets();

        router.delete(clearManualImport.url(period.id), {
            preserveScroll: true,
            only: [
                'rows',
                'period',
                'clearable_timesheet_count',
                'crew_timeline_preparation',
                'generation_summary',
            ],
            onFinish: () => {
                endClearTimesheets();
                setIsClearingTimesheets(false);
                setIsClearTimesheetsDialogOpen(false);
            },
        });
    }, [beginClearTimesheets, endClearTimesheets, period.id]);

    const [salaryInputsRecord, setSalaryInputsRecord] =
        useState<PayrollRecordListItem | null>(null);
    const [storedExcludedIds, setExcludedIds] = useState<Set<number>>(
        () => new Set(period.excluded_employee_ids ?? []),
    );
    /**
     * Employees that leave the board (filter change, record removal) must not
     * stay excluded, so stale ids are dropped while rendering rather than in an
     * effect that would trigger a second render pass.
     */
    const excludedIds = useMemo(
        () => pruneExcludedIds(storedExcludedIds, all_board_employee_ids),
        [storedExcludedIds, all_board_employee_ids],
    );
    const [removeRecord, setRemoveRecord] =
        useState<PayrollRecordListItem | null>(null);
    const [isRemovingRecord, setIsRemovingRecord] = useState(false);
    const [isPreparingTimeline, setIsPreparingTimeline] = useState(false);
    const [isReprepareDialogOpen, setIsReprepareDialogOpen] = useState(false);
    /**
     * Records are selected for WPS export by default, so the deselected ids are
     * tracked instead of the selected ones. Every partial reload hands back a
     * new all_payroll_record_ids array, and storing the selection directly meant
     * it had to be resynced on each reload, discarding the user's choice.
     */
    const [deselectedWpsRecordIds, setDeselectedWpsRecordIds] = useState<
        Set<number>
    >(() => new Set());
    const selectedWpsRecordIds = useMemo(
        () =>
            all_payroll_record_ids.filter(
                (id) => !deselectedWpsRecordIds.has(id),
            ),
        [all_payroll_record_ids, deselectedWpsRecordIds],
    );
    const [rowDates, setRowDates] = useState<
        Record<number, { start: string; end: string }>
    >({});

    const isDraftPeriod = period.status === 'draft';

    const payrollFilters: PayrollShowFilters = {
        department_id: initialFilters.department_id ?? '',
        position_id: initialFilters.position_id ?? '',
        company_visa_type_id: initialFilters.company_visa_type_id ?? '',
        employee_group: initialFilters.employee_group ?? '',
        crew_salary_structure:
            initialFilters.crew_salary_structure === 'monthly'
                ? 'monthly'
                : 'daily',
    };

    const activeCrewSalaryStructure = payrollFilters.crew_salary_structure;
    const activeEmployeeGroup = payrollFilters.employee_group;
    const activeSheetFiltersCount = [
        payrollFilters.company_visa_type_id,
    ].filter(Boolean).length;

    const list = usePayrollShowFilters({
        url: show.url(period.id),
        initialSearch,
        payrollFilters,
        pagination,
        recordsPagination: payroll_records_pagination,
        monthlyRecordsPagination: payroll_records_monthly_pagination,
        isDraft: isDraftPeriod,
        supportsTimesheets: period.supports_timesheets,
    });

    const { isLiveUpdating: isPayslipGenerationLive } =
        usePayslipGenerationPoll({
            periodStatus: period.status as PayrollPeriodStatus,
            payslipSummary: payslip_summary,
        });

    const handleEmployeeGroupSelect = (
        employeeGroup: PayrollShowFilters['employee_group'],
    ) => {
        list.applyFilters({
            department_id: payrollFilters.department_id,
            position_id: payrollFilters.position_id,
            employee_group: employeeGroup,
            ...(period.supports_timesheets
                ? {
                      crew_salary_structure: activeCrewSalaryStructure,
                  }
                : {}),
            page: null,
        });
    };

    const departmentTreeSelectionCount =
        payrollFilters.department_id || payrollFilters.position_id ? 1 : 0;

    const handleDepartmentSelect = (id: number | null) => {
        list.applyFilters({
            department_id: id !== null ? String(id) : '',
            position_id: '',
            employee_group: activeEmployeeGroup,
            ...(period.supports_timesheets
                ? {
                      crew_salary_structure: activeCrewSalaryStructure,
                  }
                : {}),
        });
    };

    const handlePositionSelect = (positionId: number, departmentId: number) => {
        list.applyFilters({
            department_id: String(departmentId),
            position_id: String(positionId),
            employee_group: activeEmployeeGroup,
            ...(period.supports_timesheets
                ? {
                      crew_salary_structure: activeCrewSalaryStructure,
                  }
                : {}),
        });
    };

    const handleCrewSalaryStructureChange = list.onCrewSalaryStructureChange;

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
                    <Button
                        type="button"
                        variant="secondary"
                        className="h-12 rounded-xl glass-card px-5 hover:bg-accent"
                        onClick={() => setIsFiltersOpen(true)}
                    >
                        <Filter className="mr-2 h-4 w-4" />
                        Filters
                        {activeSheetFiltersCount > 0 ? (
                            <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-[11px] font-bold text-primary">
                                {activeSheetFiltersCount}
                            </span>
                        ) : null}
                    </Button>
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

    const handleRevertToDraft = ({
        clearTimesheets,
    }: {
        clearTimesheets: boolean;
    }) => {
        setIsReverting(true);
        router.post(
            revertToDraft.url(period.id),
            { clear_timesheets: clearTimesheets },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setExcludedIds(new Set());
                    setDeselectedWpsRecordIds(new Set());
                },
                onFinish: () => {
                    setIsReverting(false);
                    setIsRevertDialogOpen(false);
                },
            },
        );
    };

    const handleRevertToApproved = () => {
        setIsRevertingToApproved(true);
        router.post(
            revertToApproved.url(period.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsRevertingToApproved(false);
                    setIsRevertToApprovedDialogOpen(false);
                },
            },
        );
    };

    const handleRevertToProcessing = () => {
        setIsRevertingToProcessing(true);
        router.post(
            revertToProcessing.url(period.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsRevertingToProcessing(false);
                    setIsRevertToProcessingDialogOpen(false);
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

    const handleMarkPaid = (
        files?: File[] | File | null,
        paymentDate?: string,
    ) => {
        setIsMarkingPaid(true);
        setMarkPaidDateError(undefined);
        const payload: Record<string, string | File | File[]> = {};

        if (paymentDate) {
            payload.payment_date = paymentDate;
        }

        if (Array.isArray(files) && files.length > 0) {
            payload.payment_proofs = files;
        } else if (files instanceof File) {
            payload.payment_proof = files;
        }

        router.post(markPaid.url(period.id), payload, {
            preserveScroll: true,
            onError: (errors) => {
                setMarkPaidDateError(errors.payment_date);
            },
            onSuccess: () => {
                setIsMarkPaidDialogOpen(false);
            },
            onFinish: () => {
                setIsMarkingPaid(false);
            },
        });
    };

    const handlePrepareTimeline = () => {
        setIsPreparingTimeline(true);
        router.post(
            PrepareCrewTimesheetTimelineController.url(period.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setIsPreparingTimeline(false);
                    setIsReprepareDialogOpen(false);
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

    const canClearTimesheets =
        period.status === 'draft' &&
        period.supports_timesheets &&
        permissions.clear_timesheets &&
        clearable_timesheet_count > 0;

    const isGenerationBlocked =
        period.supports_timesheets &&
        Boolean(period.generation_blocking_reason) &&
        permissions.generate_payroll &&
        period.can_generate_crew_payroll;

    const generationBlockingReason =
        period.generation_blocking_reason ??
        'Apply the approved Crew Operations timeline before generating payroll.';

    const showTimelineCard =
        period.supports_timesheets &&
        permissions.view_timeline &&
        period.uses_crew_operations_timesheets &&
        !!crew_timeline_preparation;

    const canEditTimesheets =
        period.status === 'draft' && (permissions.create || permissions.update);

    const canPrepareTimeline =
        period.status === 'draft' &&
        period.uses_crew_operations_timesheets &&
        period.supports_timesheets &&
        permissions.prepare_timeline &&
        crew_timeline_preparation?.status !== 'applied';

    const canRevertToDraft =
        period.can_revert_to_draft && permissions.revert_to_draft;
    const canRevertToApproved =
        period.can_revert_to_approved && permissions.revert_to_approved;
    const canRevertToProcessing =
        period.can_revert_to_processing && permissions.revert_to_processing;
    const canApprove = period.can_approve && permissions.approve;
    const canMarkPaid = period.can_mark_paid && permissions.mark_paid;
    const canCancelPeriod = period.can_cancel && permissions.cancel;

    const canManageSalaryInputs =
        period.can_generate_payroll &&
        (permissions.salary_inputs_create ||
            permissions.salary_inputs_update ||
            permissions.salary_inputs_delete);

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
                setDeselectedWpsRecordIds((current) => {
                    const next = new Set(current);

                    if (next.has(recordId)) {
                        next.delete(recordId);
                    } else {
                        next.add(recordId);
                    }

                    return next;
                });
            },
            onToggleAll: () => {
                const pageRecordIds = new Set(recordIds);

                setDeselectedWpsRecordIds(
                    allSelected
                        ? new Set(all_payroll_record_ids)
                        : new Set(
                              all_payroll_record_ids.filter(
                                  (id) => !pageRecordIds.has(id),
                              ),
                          ),
                );
            },
        };
    }, [
        all_payroll_record_ids,
        canSelectForWpsExport,
        payroll_records,
        selectedWpsRecordIds,
    ]);

    const isProcessingPayRun = period.status === 'processing';
    const hasPayrollRecords = period.payroll_records_count > 0;
    const headerPrimaryActionClass =
        'h-12 rounded-xl border-0 px-6 bg-gradient-to-r from-blue-600 to-indigo-500 text-white hover:from-blue-700 hover:to-indigo-600 hover:text-white shadow-lg shadow-blue-500/25 transition-all duration-300 hover:scale-105 active:scale-95';
    const headerSecondaryActionClass =
        'h-12 rounded-xl px-6 border border-border/50 bg-secondary/50 text-foreground backdrop-blur-md hover:bg-secondary/80 hover:text-foreground transition-all duration-300';

    const hasHeaderActions =
        canGenerate ||
        isGenerationBlocked ||
        canPrepareTimeline ||
        canRevertToDraft ||
        canRevertToApproved ||
        canRevertToProcessing ||
        canApprove ||
        canMarkPaid ||
        canCancelPeriod ||
        canClearTimesheets ||
        permissions.export_payroll ||
        Boolean(period.has_payment_proof);

    const recordsPagination = payroll_records_pagination;
    const monthlyRecordsPagination = payroll_records_monthly_pagination;

    const payrollRecordsQuery = useMemo(
        () => ({
            crew_salary_structure: activeCrewSalaryStructure,
            search: initialSearch || undefined,
        }),
        [activeCrewSalaryStructure, initialSearch],
    );

    const handleDailyRecordsPageChange = useCallback(
        (page: number) => {
            list.visit({
                ...payrollRecordsQuery,
                records_page: page,
                monthly_records_page:
                    monthlyRecordsPagination?.current_page ?? undefined,
            });
        },
        [list, monthlyRecordsPagination?.current_page, payrollRecordsQuery],
    );

    const handleMonthlyRecordsPageChange = useCallback(
        (page: number) => {
            list.visit({
                ...payrollRecordsQuery,
                monthly_records_page: page,
                records_page: recordsPagination?.current_page ?? undefined,
            });
        },
        [list, payrollRecordsQuery, recordsPagination?.current_page],
    );

    const handleOfficeRecordsPageChange = useCallback(
        (page: number) => {
            list.visit({
                records_page: page,
                search: initialSearch || undefined,
            });
        },
        [initialSearch, list],
    );

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
                        {period.crew_timesheet_mode_label &&
                        period.crew_timesheet_mode !== 'hybrid' ? (
                            <Badge variant="outline">
                                {period.crew_timesheet_mode_label}
                            </Badge>
                        ) : null}
                        <PayrollCreationSourceBadge
                            source={period.creation_source}
                            label={period.creation_source_label}
                        />
                    </span>
                }
                description={`${formatDisplayDate(period.start_date)} — ${formatDisplayDate(period.end_date)} · Generated ${period.generated_at ? formatDisplayDate(period.generated_at) : 'Not generated'} · Payment ${period.payment_date ? formatDisplayDate(period.payment_date) : 'Pending'}`}
                backHref={payrollIndex.url()}
                backLabel="Go back"
                actions={
                    hasHeaderActions ? (
                        <>
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
                            {canClearTimesheets ? (
                                <Button
                                    variant="outline"
                                    className="h-12 rounded-xl border-destructive/30 bg-destructive/5 px-6 text-destructive transition-all duration-300 hover:bg-destructive/15 hover:text-destructive"
                                    disabled={isClearingTimesheets}
                                    onClick={() =>
                                        setIsClearTimesheetsDialogOpen(true)
                                    }
                                >
                                    <Eraser className="mr-2 h-4 w-4" />
                                    {isClearingTimesheets
                                        ? 'Clearing…'
                                        : 'Clear Timesheets'}
                                </Button>
                            ) : null}
                            {canPrepareTimeline &&
                            !crew_timeline_preparation ? (
                                <Button
                                    variant="outline"
                                    className={headerSecondaryActionClass}
                                    disabled={isPreparingTimeline}
                                    onClick={handlePrepareTimeline}
                                >
                                    <Ship className="mr-2 h-4 w-4" />
                                    {isPreparingTimeline
                                        ? 'Preparing…'
                                        : 'Prepare from Crew Operations'}
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
                            {canRevertToProcessing ? (
                                <Button
                                    variant="outline"
                                    className={headerSecondaryActionClass}
                                    onClick={() =>
                                        setIsRevertToProcessingDialogOpen(true)
                                    }
                                >
                                    <RotateCcw className="mr-2 h-4 w-4" />
                                    Revert to processing
                                </Button>
                            ) : null}
                            {canRevertToApproved ? (
                                <Button
                                    variant="outline"
                                    className={headerSecondaryActionClass}
                                    onClick={() =>
                                        setIsRevertToApprovedDialogOpen(true)
                                    }
                                >
                                    <RotateCcw className="mr-2 h-4 w-4" />
                                    Revert to approved
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
                                        isProcessingPayRun
                                            ? 'outline'
                                            : undefined
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
                            ) : isGenerationBlocked ? (
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <span className="inline-flex">
                                            <Button
                                                disabled
                                                className={
                                                    headerPrimaryActionClass
                                                }
                                            >
                                                <Calculator className="mr-2 h-4 w-4" />
                                                Generate payroll
                                            </Button>
                                        </span>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        {generationBlockingReason}
                                    </TooltipContent>
                                </Tooltip>
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
                            {permissions.export_payroll ? (
                                <Button
                                    asChild
                                    variant="outline"
                                    className={headerSecondaryActionClass}
                                >
                                    <a href={exportPayroll.url(period.id)}>
                                        <Download className="mr-2 h-4 w-4" />
                                        Export Excel
                                    </a>
                                </Button>
                            ) : null}
                        </>
                    ) : null
                }
            />

            {/* ── Status Timeline ─────────────────────────── */}
            <PayrollStatusTimeline
                status={period.status}
                approver={period.approver}
            />

            {showTimelineCard && crew_timeline_preparation ? (
                <Card className="glass-card">
                    <CardContent className="flex flex-col gap-4 p-5">
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div className="space-y-2">
                                <div className="flex flex-wrap items-center gap-2">
                                    <p className="text-sm font-semibold">
                                        Crew Operations timeline
                                    </p>
                                    <CrewTimelineStatusBadge
                                        status={
                                            crew_timeline_preparation.status
                                        }
                                        label={
                                            crew_timeline_preparation.status_label
                                        }
                                    />
                                    <Badge variant="outline">
                                        Version{' '}
                                        {crew_timeline_preparation.version}
                                    </Badge>
                                    <Badge
                                        variant="outline"
                                        className={
                                            crew_timeline_preparation.is_stale
                                                ? 'border-red-500/40 text-red-700 dark:text-red-300'
                                                : 'border-emerald-500/40 text-emerald-700 dark:text-emerald-300'
                                        }
                                    >
                                        {crew_timeline_preparation.is_fresh
                                            ? 'Fresh'
                                            : 'Timeline changed'}
                                    </Badge>
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    Blocking warnings:{' '}
                                    {
                                        crew_timeline_preparation.blocking_warning_count
                                    }{' '}
                                    · Informational warnings:{' '}
                                    {
                                        crew_timeline_preparation.informational_warning_count
                                    }
                                    {crew_timeline_preparation.status ===
                                    'applied'
                                        ? ` · Applied to ${crew_timeline_preparation.linked_timesheet_count} timesheet(s)`
                                        : null}
                                </p>
                                {crew_timeline_preparation.status ===
                                'applied' ? (
                                    <p className="text-sm text-emerald-700 dark:text-emerald-300">
                                        Operational timesheets came from Crew
                                        Operations.
                                    </p>
                                ) : null}
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                {canPrepareTimeline ? (
                                    <Button
                                        variant="outline"
                                        disabled={isPreparingTimeline}
                                        onClick={() =>
                                            setIsReprepareDialogOpen(true)
                                        }
                                    >
                                        <RotateCcw className="mr-2 h-4 w-4" />
                                        Re-prepare (new version)
                                    </Button>
                                ) : null}
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        router.visit(
                                            crewTimelineShow.url([
                                                period.id,
                                                crew_timeline_preparation.id,
                                            ]),
                                        )
                                    }
                                >
                                    <Ship className="mr-2 h-4 w-4" />
                                    {crew_timeline_preparation.status ===
                                    'applied'
                                        ? 'View Timeline'
                                        : 'Review Timeline'}
                                </Button>
                            </div>
                        </div>
                        {isGenerationBlocked ? (
                            <div className="flex items-start gap-3 rounded-xl border border-amber-500/30 bg-amber-500/5 p-4">
                                <AlertCircle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
                                <div className="space-y-1">
                                    <p className="text-sm font-semibold text-amber-900 dark:text-amber-100">
                                        Payroll generation blocked
                                    </p>
                                    <p className="text-sm text-amber-800/90 dark:text-amber-200/90">
                                        {generationBlockingReason}
                                    </p>
                                </div>
                            </div>
                        ) : null}
                    </CardContent>
                </Card>
            ) : null}

            {isGenerationBlocked && !showTimelineCard ? (
                <Card className="glass-card border-amber-500/30 bg-amber-500/5">
                    <CardContent className="flex items-start gap-3 p-5">
                        <AlertCircle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
                        <div className="space-y-1">
                            <p className="text-sm font-semibold text-amber-900 dark:text-amber-100">
                                Payroll generation blocked
                            </p>
                            <p className="text-sm text-amber-800/90 dark:text-amber-200/90">
                                {generationBlockingReason}
                            </p>
                        </div>
                    </CardContent>
                </Card>
            ) : null}

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
                              <div className="flex flex-wrap items-center gap-2">
                                  <CrewSalaryStructureToggle
                                      value={activeCrewSalaryStructure}
                                      onChange={handleCrewSalaryStructureChange}
                                  />
                                  {permissions.import_timesheets ? (
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
                                  ) : null}
                              </div>,
                          )
                        : renderListToolbar()}

                    {period.supports_timesheets ? (
                        <CrewTimesheetsBoard
                            period={period}
                            rows={rows}
                            pagination={pagination}
                            paginationProps={list.paginationProps}
                            allBoardEmployeeIds={all_board_employee_ids}
                            excludedIds={excludedIds}
                            setExcludedIds={setExcludedIds}
                            employee_stats={employee_stats}
                            activeEmployeeGroup={activeEmployeeGroup}
                            onEmployeeGroupSelect={handleEmployeeGroupSelect}
                            activeCrewSalaryStructure={
                                activeCrewSalaryStructure
                            }
                            crewTimesheetDrafts={crewTimesheetDrafts}
                            onCrewTimesheetChange={handleCrewTimesheetChange}
                            savingTimesheetEmployeeIds={
                                savingTimesheetEmployeeIds
                            }
                            financialAutosaveErrors={financialAutosaveErrors}
                            onRetryFinancialAutosave={retryFinancialAutosave}
                            canEditTimesheets={canEditTimesheets}
                            onOpenMovementPeriods={(row, categoryGroup) =>
                                setMovementPeriodsTarget({
                                    row,
                                    categoryGroup,
                                })
                            }
                        />
                    ) : (
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
                    )}
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
                            <div className="flex shrink-0 flex-wrap items-center gap-3">
                                <DepartmentFilterControls
                                    department_tree={department_tree}
                                    department_tree_selected_id={
                                        department_tree_selected_id
                                    }
                                    department_tree_selected_position_id={
                                        department_tree_selected_position_id
                                    }
                                    selectionCount={
                                        departmentTreeSelectionCount
                                    }
                                    onSelectDepartment={handleDepartmentSelect}
                                    onSelectPosition={handlePositionSelect}
                                />
                                <Button
                                    type="button"
                                    variant="secondary"
                                    className="h-12 rounded-xl glass-card px-5 hover:bg-accent"
                                    onClick={() => setIsFiltersOpen(true)}
                                >
                                    <Filter className="mr-2 h-4 w-4" />
                                    Filters
                                    {activeSheetFiltersCount > 0 ? (
                                        <span className="ml-2 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-primary/20 px-1.5 text-[11px] font-bold text-primary">
                                            {activeSheetFiltersCount}
                                        </span>
                                    ) : null}
                                </Button>
                                {period.supports_timesheets ? (
                                    <CrewSalaryStructureToggle
                                        value={activeCrewSalaryStructure}
                                        onChange={
                                            handleCrewSalaryStructureChange
                                        }
                                    />
                                ) : null}
                            </div>
                        }
                    />
                    <PayrollSkippedBanner summary={generation_summary} />
                    <PayrollPeriodDeliveryPanel
                        period={period}
                        payslip_summary={payslip_summary}
                        wps_preview={wps_preview}
                        permissions={permissions}
                        selectedWpsRecordIds={
                            canSelectForWpsExport ? selectedWpsRecordIds : null
                        }
                        isPayslipGenerationLive={isPayslipGenerationLive}
                    />
                    {payroll_records_summary ? (
                        <PayrollRecordsSummaryCards
                            summary={payroll_records_summary}
                            activeCrewSalaryStructure={
                                period.supports_timesheets
                                    ? activeCrewSalaryStructure
                                    : null
                            }
                        />
                    ) : null}
                    <PayrollRecordsBoard
                        period={period}
                        hasPayrollRecords={hasPayrollRecords}
                        canGenerate={canGenerate}
                        isGenerationBlocked={isGenerationBlocked}
                        generationBlockingReason={generationBlockingReason}
                        onOpenGenerateDialog={() =>
                            setIsGenerateDialogOpen(true)
                        }
                        payroll_records={payroll_records}
                        payroll_records_monthly={payroll_records_monthly}
                        activeCrewSalaryStructure={activeCrewSalaryStructure}
                        salary_inputs_by_employee={salary_inputs_by_employee}
                        canManageSalaryInputs={canManageSalaryInputs}
                        wpsSelection={wpsSelection}
                        onManageSalaryInputs={setSalaryInputsRecord}
                        onRemove={setRemoveRecord}
                        isPayslipGenerationLive={isPayslipGenerationLive}
                        recordsPagination={recordsPagination}
                        monthlyRecordsPagination={monthlyRecordsPagination}
                        onDailyRecordsPageChange={handleDailyRecordsPageChange}
                        onMonthlyRecordsPageChange={
                            handleMonthlyRecordsPageChange
                        }
                        onOfficeRecordsPageChange={
                            handleOfficeRecordsPageChange
                        }
                    />
                </section>
            )}

            {period.supports_timesheets && isImportDialogOpen ? (
                <CrewTimesheetImportDialog
                    open={isImportDialogOpen}
                    onOpenChange={setIsImportDialogOpen}
                    periodId={period.id}
                />
            ) : null}

            {period.supports_timesheets && movementPeriodsTarget !== null ? (
                <CrewMovementPeriodsDialog
                    open={movementPeriodsTarget !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setMovementPeriodsTarget(null);
                        }
                    }}
                    period={period}
                    row={
                        rows.find(
                            (row) =>
                                row.employee.id ===
                                movementPeriodsTarget.row.employee.id,
                        ) ?? movementPeriodsTarget.row
                    }
                    categoryGroup={movementPeriodsTarget.categoryGroup}
                    masterOptions={
                        movement_master_options ?? {
                            vessels: [],
                            clients: [],
                            ranks: [],
                        }
                    }
                    canEdit={canEditTimesheets}
                    onBeforeSave={async () => {
                        const employeeId =
                            movementPeriodsTarget.row.employee.id;
                        const latestTimesheet =
                            rows.find((row) => row.employee.id === employeeId)
                                ?.timesheet ??
                            movementPeriodsTarget.row.timesheet;

                        await flushPendingFinancialSave(
                            employeeId,
                            latestTimesheet,
                        );
                    }}
                    onSaved={() => {
                        clearEmployeeDraft(
                            movementPeriodsTarget.row.employee.id,
                        );
                    }}
                />
            ) : null}

            {period.supports_timesheets && isClearTimesheetsDialogOpen ? (
                <ClearCrewTimesheetsDialog
                    open={isClearTimesheetsDialogOpen}
                    onOpenChange={(open) => {
                        if (!isClearingTimesheets) {
                            setIsClearTimesheetsDialogOpen(open);
                        }
                    }}
                    clearableCount={clearable_timesheet_count}
                    isClearing={isClearingTimesheets}
                    onConfirm={() => {
                        void handleConfirmClearTimesheets();
                    }}
                />
            ) : null}

            {salaryInputsRecord !== null ? (
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
            ) : null}

            <PayrollShowFiltersSheet
                open={isFiltersOpen}
                onOpenChange={setIsFiltersOpen}
                companyVisaTypes={company_visa_types}
                supportsTimesheets={period.supports_timesheets}
                value={{
                    company_visa_type_id: payrollFilters.company_visa_type_id,
                    crew_timesheet_filter:
                        payrollFilters.crew_timesheet_filter ?? '',
                }}
                onChange={(next) => {
                    list.applyFilters({
                        company_visa_type_id: next.company_visa_type_id,
                        crew_timesheet_filter: next.crew_timesheet_filter,
                        page: null,
                        records_page: null,
                        monthly_records_page: null,
                    });
                }}
                onReset={() => {
                    list.applyFilters({
                        company_visa_type_id: '',
                        crew_timesheet_filter: '',
                        page: null,
                        records_page: null,
                        monthly_records_page: null,
                    });
                }}
            />

            {isGenerateDialogOpen ? (
                <PayrollGenerateDialog
                    open={isGenerateDialogOpen}
                    onOpenChange={setIsGenerateDialogOpen}
                    onConfirm={handleGeneratePayroll}
                    processing={isGenerating}
                    payrollCategory={period.payroll_category}
                    periodId={period.id}
                    hasExistingRecords={hasPayrollRecords}
                    excludedCount={excludedIds.size}
                    excludedEmployeeIds={Array.from(excludedIds)}
                />
            ) : null}

            {isRevertDialogOpen ? (
                <PayrollRevertToDraftDialog
                    open={isRevertDialogOpen}
                    onOpenChange={setIsRevertDialogOpen}
                    onConfirm={handleRevertToDraft}
                    processing={isReverting}
                    supportsTimesheets={period.supports_timesheets}
                />
            ) : null}

            {isRevertToApprovedDialogOpen ? (
                <PayrollRevertToApprovedDialog
                    open={isRevertToApprovedDialogOpen}
                    onOpenChange={setIsRevertToApprovedDialogOpen}
                    onConfirm={handleRevertToApproved}
                    processing={isRevertingToApproved}
                />
            ) : null}

            {isRevertToProcessingDialogOpen ? (
                <PayrollRevertToProcessingDialog
                    open={isRevertToProcessingDialogOpen}
                    onOpenChange={setIsRevertToProcessingDialogOpen}
                    onConfirm={handleRevertToProcessing}
                    processing={isRevertingToProcessing}
                />
            ) : null}

            {isApproveDialogOpen ? (
                <PayrollApproveDialog
                    open={isApproveDialogOpen}
                    onOpenChange={setIsApproveDialogOpen}
                    onConfirm={handleApprove}
                    processing={isApproving}
                />
            ) : null}

            {isMarkPaidDialogOpen ? (
                <PayrollMarkPaidDialog
                    open={isMarkPaidDialogOpen}
                    onOpenChange={(open) => {
                        setIsMarkPaidDialogOpen(open);

                        if (!open) {
                            setMarkPaidDateError(undefined);
                        }
                    }}
                    onConfirm={handleMarkPaid}
                    processing={isMarkingPaid}
                    paymentDateError={markPaidDateError}
                />
            ) : null}

            {isCancelDialogOpen ? (
                <PayrollCancelDialog
                    open={isCancelDialogOpen}
                    onOpenChange={setIsCancelDialogOpen}
                    onConfirm={handleCancel}
                    processing={isCancelling}
                />
            ) : null}

            {crew_timeline_preparation && isReprepareDialogOpen ? (
                <PayrollReprepareTimelineDialog
                    open={isReprepareDialogOpen}
                    onOpenChange={setIsReprepareDialogOpen}
                    onConfirm={handlePrepareTimeline}
                    processing={isPreparingTimeline}
                    currentVersion={crew_timeline_preparation.version}
                />
            ) : null}

            {removeRecord !== null ? (
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
            ) : null}
        </Main>
    );
}
