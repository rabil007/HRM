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
    Download,
    Filter,
    Paperclip,
    RotateCcw,
    Ship,
    Upload,
    Users,
    XCircle,
} from 'lucide-react';
import React, {
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import {
    approve as approveTimesheet,
    returnMethod as returnTimesheet,
    submit as submitTimesheet,
} from '@/actions/App/Http/Controllers/Payroll/CrewTimesheetApprovalController';
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
    storeTimesheet,
} from '@/actions/App/Http/Controllers/Payroll/PayrollController';
import PrepareCrewTimesheetTimelineController from '@/actions/App/Http/Controllers/Payroll/PrepareCrewTimesheetTimelineController';
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
import { Card, CardContent } from '@/components/ui/card';
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
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { show as crewTimelineShow } from '@/routes/payroll/crew-timeline';
import { CrewOperationalSourceBadge } from './components/crew-operational-source-badge';
import { CrewSalaryStructureToggle } from './components/crew-salary-structure-toggle';
import { CrewTimesheetApprovalBadge } from './components/crew-timesheet-approval-badge';
import { CrewTimesheetImportDialog } from './components/crew-timesheet-import-dialog';
import { OfficePayrollRecordsTable } from './components/office-payroll-records-table';
import { OfficeSalaryInputsSheet } from './components/office-salary-inputs-sheet';
import { PayrollApproveDialog } from './components/payroll-approve-dialog';
import { PayrollCancelDialog } from './components/payroll-cancel-dialog';
import { PayrollCategoryBadge } from './components/payroll-category-badge';
import { PayrollCreationSourceBadge } from './components/payroll-creation-source-badge';
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
import { PayrollReprepareTimelineDialog } from './components/payroll-reprepare-timeline-dialog';
import { PayrollRevertToApprovedDialog } from './components/payroll-revert-to-approved-dialog';
import { PayrollRevertToDraftDialog } from './components/payroll-revert-to-draft-dialog';
import { PayrollRevertToProcessingDialog } from './components/payroll-revert-to-processing-dialog';
import { PayrollShowFiltersSheet } from './components/payroll-show-filters-sheet';
import { PayrollSkippedBanner } from './components/payroll-skipped-banner';
import { CrewTimelineStatusBadge } from './crew-timeline/crew-timeline-status-badge';
import { usePayslipGenerationPoll } from './hooks/use-payslip-generation-poll';
import { calculateInclusiveDays } from './lib/calculate-inclusive-days';
import {
    getPayrollBoardSelectionSummary,
    pruneExcludedIds,
} from './lib/payroll-board-selection';
import type {
    CrewPayrollRecordListItem,
    CrewPayrollRow,
    CrewTimesheetDraft,
    EmployeeStats,
    OfficePayrollRecordListItem,
    PayrollPeriod,
    PayrollRecordListItem,
    PayrollPeriodStatus,
    PayrollShowFilters,
    PayrollShowProps,
    SalaryInput,
} from './types';
import { buildCrewTimesheetDraft, formatTimesheetDays } from './types';
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
    generation_readiness = null,
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
    const [isFiltersOpen, setIsFiltersOpen] = useState(false);
    const [salaryInputsRecord, setSalaryInputsRecord] =
        useState<PayrollRecordListItem | null>(null);
    const [excludedIds, setExcludedIds] = useState<Set<number>>(
        () => new Set(period.excluded_employee_ids ?? []),
    );
    const [removeRecord, setRemoveRecord] =
        useState<PayrollRecordListItem | null>(null);
    const [isRemovingRecord, setIsRemovingRecord] = useState(false);
    const [isPreparingTimeline, setIsPreparingTimeline] = useState(false);
    const [isReprepareDialogOpen, setIsReprepareDialogOpen] = useState(false);
    const [selectedWpsRecordIds, setSelectedWpsRecordIds] = useState<number[]>(
        () => all_payroll_record_ids,
    );
    const [rowDates, setRowDates] = useState<
        Record<number, { start: string; end: string }>
    >({});
    const [crewTimesheetDrafts, setCrewTimesheetDrafts] = useState<
        Record<number, CrewTimesheetDraft>
    >({});
    const crewTimesheetDraftsRef = useRef(crewTimesheetDrafts);
    const crewSaveTimersRef = useRef<
        Record<number, ReturnType<typeof setTimeout>>
    >({});

    useEffect(() => {
        crewTimesheetDraftsRef.current = crewTimesheetDrafts;
    }, [crewTimesheetDrafts]);

    useEffect(() => {
        const timers = crewSaveTimersRef.current;

        return () => {
            Object.values(timers).forEach((timer) => {
                clearTimeout(timer);
            });
        };
    }, []);

    const handleCrewTimesheetChange = (
        employeeId: number,
        field: keyof CrewTimesheetDraft,
        val: string,
        initialTimesheet: CrewPayrollRow['timesheet'],
    ) => {
        setCrewTimesheetDrafts((prev) => {
            const existing =
                prev[employeeId] ?? buildCrewTimesheetDraft(initialTimesheet);

            const next = {
                ...prev,
                [employeeId]: {
                    ...existing,
                    [field]: val,
                },
            };

            crewTimesheetDraftsRef.current = next;

            return next;
        });

        scheduleSaveCrewTimesheet(employeeId, initialTimesheet);
    };

    const saveCrewTimesheet = useCallback(
        (employeeId: number, initialTimesheet: CrewPayrollRow['timesheet']) => {
            const current = crewTimesheetDraftsRef.current[employeeId];

            if (!current) {
                return;
            }

            const submittedDraft: CrewTimesheetDraft = { ...current };

            router.post(
                storeTimesheet.url(period.id),
                {
                    period_id: period.id,
                    employee_id: employeeId,
                    sign_on_standby_from: current.sign_on_standby_from || null,
                    sign_on_standby_to: current.sign_on_standby_to || null,
                    onsite_from: current.onsite_from || null,
                    onsite_to: current.onsite_to || null,
                    sign_off_standby_from:
                        current.sign_off_standby_from || null,
                    sign_off_standby_to: current.sign_off_standby_to || null,
                    unpaid_leave_days: current.unpaid_leave_days || null,
                    overtime_hours: current.overtime_hours || 0,
                    additional_amount: initialTimesheet?.additional_amount ?? 0,
                    deduction_amount: initialTimesheet?.deduction_amount ?? 0,
                    remarks: initialTimesheet?.remarks ?? null,
                },
                {
                    preserveScroll: true,
                    preserveState: true,
                    only: ['rows'],
                    onSuccess: () => {
                        setCrewTimesheetDrafts((prev) => {
                            const draft = prev[employeeId];

                            if (!draft) {
                                return prev;
                            }

                            if (crewSaveTimersRef.current[employeeId]) {
                                return prev;
                            }

                            if (
                                draft.sign_on_standby_from !==
                                    submittedDraft.sign_on_standby_from ||
                                draft.sign_on_standby_to !==
                                    submittedDraft.sign_on_standby_to ||
                                draft.onsite_from !==
                                    submittedDraft.onsite_from ||
                                draft.onsite_to !== submittedDraft.onsite_to ||
                                draft.sign_off_standby_from !==
                                    submittedDraft.sign_off_standby_from ||
                                draft.sign_off_standby_to !==
                                    submittedDraft.sign_off_standby_to ||
                                draft.unpaid_leave_days !==
                                    submittedDraft.unpaid_leave_days ||
                                draft.overtime_hours !==
                                    submittedDraft.overtime_hours
                            ) {
                                return prev;
                            }

                            const next = { ...prev };
                            delete next[employeeId];
                            crewTimesheetDraftsRef.current = next;

                            return next;
                        });
                    },
                },
            );
        },
        [period.id],
    );

    const scheduleSaveCrewTimesheet = useCallback(
        (employeeId: number, initialTimesheet: CrewPayrollRow['timesheet']) => {
            const existingTimer = crewSaveTimersRef.current[employeeId];

            if (existingTimer) {
                clearTimeout(existingTimer);
            }

            crewSaveTimersRef.current[employeeId] = setTimeout(() => {
                delete crewSaveTimersRef.current[employeeId];
                saveCrewTimesheet(employeeId, initialTimesheet);
            }, 800);
        },
        [saveCrewTimesheet],
    );

    useEffect(() => {
        setSelectedWpsRecordIds(all_payroll_record_ids);
    }, [period.id, all_payroll_record_ids]);

    useEffect(() => {
        setExcludedIds((current) =>
            pruneExcludedIds(current, all_board_employee_ids),
        );
    }, [all_board_employee_ids]);

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
                    setSelectedWpsRecordIds([]);
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

    const isGenerationBlocked =
        period.supports_timesheets &&
        Boolean(period.generation_blocking_reason) &&
        permissions.generate_payroll &&
        period.can_generate_crew_payroll;

    const generationBlockingReason =
        period.generation_blocking_reason ??
        generation_readiness?.period_blocking_reason ??
        generation_readiness?.blocking_reason ??
        'Apply the approved Crew Operations timeline before generating payroll.';

    const coveragePreview = generation_readiness ?? period.generation_preview;
    const coverageSummaryParts = [
        coveragePreview && coveragePreview.missing_timesheet_count > 0
            ? `${coveragePreview.missing_timesheet_count} employees have no timesheet and will be skipped when payroll is generated`
            : null,
        coveragePreview && coveragePreview.awaiting_approval_count > 0
            ? `${coveragePreview.awaiting_approval_count} employees are awaiting approval and will be skipped`
            : null,
    ].filter(Boolean) as string[];

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
        isGenerationBlocked ||
        canPrepareTimeline ||
        canRevertToDraft ||
        canRevertToApproved ||
        canRevertToProcessing ||
        canApprove ||
        canMarkPaid ||
        canCancelPeriod ||
        permissions.export_payroll ||
        Boolean(period.has_payment_proof);

    const recordsPagination = payroll_records_pagination;
    const monthlyRecordsPagination = payroll_records_monthly_pagination;

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
                        </div>
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

            {!isGenerationBlocked &&
            period.supports_timesheets &&
            coverageSummaryParts.length > 0 &&
            period.can_generate_crew_payroll ? (
                <Card className="glass-card border-amber-500/20 bg-amber-500/5">
                    <CardContent className="flex items-start gap-3 p-5">
                        <AlertCircle className="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
                        <div className="space-y-1">
                            <p className="text-sm font-semibold text-amber-900 dark:text-amber-100">
                                Timesheet coverage
                            </p>
                            {coverageSummaryParts.map((part) => (
                                <p
                                    key={part}
                                    className="text-sm text-amber-800/90 dark:text-amber-200/90"
                                >
                                    {part}.
                                </p>
                            ))}
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

            <PayrollRevertToDraftDialog
                open={isRevertDialogOpen}
                onOpenChange={setIsRevertDialogOpen}
                onConfirm={handleRevertToDraft}
                processing={isReverting}
                supportsTimesheets={period.supports_timesheets}
            />

            <PayrollRevertToApprovedDialog
                open={isRevertToApprovedDialogOpen}
                onOpenChange={setIsRevertToApprovedDialogOpen}
                onConfirm={handleRevertToApproved}
                processing={isRevertingToApproved}
            />

            <PayrollRevertToProcessingDialog
                open={isRevertToProcessingDialogOpen}
                onOpenChange={setIsRevertToProcessingDialogOpen}
                onConfirm={handleRevertToProcessing}
                processing={isRevertingToProcessing}
            />

            <PayrollApproveDialog
                open={isApproveDialogOpen}
                onOpenChange={setIsApproveDialogOpen}
                onConfirm={handleApprove}
                processing={isApproving}
            />

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

            <PayrollCancelDialog
                open={isCancelDialogOpen}
                onOpenChange={setIsCancelDialogOpen}
                onConfirm={handleCancel}
                processing={isCancelling}
            />

            {crew_timeline_preparation ? (
                <PayrollReprepareTimelineDialog
                    open={isReprepareDialogOpen}
                    onOpenChange={setIsReprepareDialogOpen}
                    onConfirm={handlePrepareTimeline}
                    processing={isPreparingTimeline}
                    currentVersion={crew_timeline_preparation.version}
                />
            ) : null}

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
                        description={`Only active employees with an active ${period.payroll_category_label.toLowerCase()} contract appear on this pay run.`}
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
                                        colSpan={3}
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
                                        colSpan={1}
                                        className="h-7 border-x border-b border-amber-500/15 bg-amber-500/3 px-3 text-center text-[10px] font-bold tracking-[0.15em] text-amber-600/60 uppercase dark:text-amber-400/60"
                                    >
                                        Overtime
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
                                    <DataTableHead>Source</DataTableHead>
                                    <DataTableHead>Approval</DataTableHead>
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
                                    <DataTableHead className="border-x border-amber-500/10 bg-amber-500/3 text-right">
                                        Hours
                                    </DataTableHead>
                                    <DataTableHead>Payment</DataTableHead>
                                    <DataTableHead>Status</DataTableHead>
                                </DataTableHeaderRow>
                            </TableHeader>
                            <TableBody>
                                {rows.map((row) => {
                                    const isExcluded = excludedIds.has(
                                        row.employee.id,
                                    );
                                    const paymentMethod =
                                        (row.salary_payment_method ??
                                            'bank_transfer') as SalaryPaymentMethodValue;
                                    const contract = row.contract ?? null;
                                    const isMonthlyCrewRow =
                                        row.salary_structure === 'monthly';

                                    const currentDraft =
                                        crewTimesheetDrafts[row.employee.id];
                                    const signOnFrom =
                                        currentDraft?.sign_on_standby_from ??
                                        row.timesheet?.sign_on_standby_from ??
                                        '';
                                    const signOnTo =
                                        currentDraft?.sign_on_standby_to ??
                                        row.timesheet?.sign_on_standby_to ??
                                        '';
                                    const onsiteFrom =
                                        currentDraft?.onsite_from ??
                                        row.timesheet?.onsite_from ??
                                        '';
                                    const onsiteTo =
                                        currentDraft?.onsite_to ??
                                        row.timesheet?.onsite_to ??
                                        '';
                                    const signOffFrom =
                                        currentDraft?.sign_off_standby_from ??
                                        row.timesheet?.sign_off_standby_from ??
                                        '';
                                    const signOffTo =
                                        currentDraft?.sign_off_standby_to ??
                                        row.timesheet?.sign_off_standby_to ??
                                        '';
                                    const unpaidLeaveDays =
                                        currentDraft?.unpaid_leave_days ??
                                        row.timesheet?.unpaid_leave_days ??
                                        '';
                                    const overtimeHours =
                                        currentDraft?.overtime_hours ??
                                        row.timesheet?.overtime_hours ??
                                        '';

                                    const isOperationallyLocked =
                                        !isMonthlyCrewRow &&
                                        row.timesheet
                                            ?.is_operationally_locked === true;

                                    const isDirty =
                                        !!crewTimesheetDrafts[row.employee.id];

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
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
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
                                            <TableCell
                                                className={cn(
                                                    dataTableCellClass(),
                                                    'align-middle',
                                                )}
                                            >
                                                <CrewOperationalSourceBadge
                                                    source={
                                                        row.operational_source ??
                                                        (isMonthlyCrewRow
                                                            ? 'monthly_crew'
                                                            : row.timesheet
                                                              ? ((row.timesheet
                                                                    .source as
                                                                    | 'crew_operations'
                                                                    | 'import'
                                                                    | 'manual') ??
                                                                'manual')
                                                              : 'not_entered')
                                                    }
                                                    label={
                                                        row.operational_source_label
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell
                                                className={cn(
                                                    dataTableCellClass(),
                                                    'align-middle',
                                                )}
                                            >
                                                <div className="flex flex-col items-start gap-1.5">
                                                    <CrewTimesheetApprovalBadge
                                                        status={
                                                            row.approval_status
                                                        }
                                                        label={
                                                            row.approval_status_label
                                                        }
                                                    />
                                                    {row.timesheet &&
                                                    period.status === 'draft' &&
                                                    row.timesheet.source !==
                                                        'crew_operations' ? (
                                                        <div className="flex flex-wrap gap-1">
                                                            {(row.timesheet
                                                                .approval_status ===
                                                                'draft' ||
                                                                row.timesheet
                                                                    .approval_status ===
                                                                    'returned') &&
                                                            permissions.submit_timesheet ? (
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    className="h-7 px-2 text-[11px]"
                                                                    onClick={() =>
                                                                        router.post(
                                                                            submitTimesheet.url(
                                                                                {
                                                                                    payrollPeriod:
                                                                                        period.id,
                                                                                    timesheet:
                                                                                        row
                                                                                            .timesheet!
                                                                                            .id,
                                                                                },
                                                                            ),
                                                                            {},
                                                                            {
                                                                                preserveScroll: true,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    Submit
                                                                </Button>
                                                            ) : null}
                                                            {row.timesheet
                                                                .approval_status ===
                                                                'submitted' &&
                                                            permissions.approve_timesheet ? (
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    className="h-7 px-2 text-[11px]"
                                                                    onClick={() =>
                                                                        router.post(
                                                                            approveTimesheet.url(
                                                                                {
                                                                                    payrollPeriod:
                                                                                        period.id,
                                                                                    timesheet:
                                                                                        row
                                                                                            .timesheet!
                                                                                            .id,
                                                                                },
                                                                            ),
                                                                            {},
                                                                            {
                                                                                preserveScroll: true,
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    Approve
                                                                </Button>
                                                            ) : null}
                                                            {row.timesheet
                                                                .approval_status ===
                                                                'submitted' &&
                                                            permissions.return_timesheet ? (
                                                                <Button
                                                                    type="button"
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    className="h-7 px-2 text-[11px]"
                                                                    onClick={() => {
                                                                        const reason =
                                                                            window.prompt(
                                                                                'Return reason',
                                                                            );

                                                                        if (
                                                                            !reason?.trim()
                                                                        ) {
                                                                            return;
                                                                        }

                                                                        router.post(
                                                                            returnTimesheet.url(
                                                                                {
                                                                                    payrollPeriod:
                                                                                        period.id,
                                                                                    timesheet:
                                                                                        row
                                                                                            .timesheet!
                                                                                            .id,
                                                                                },
                                                                            ),
                                                                            {
                                                                                return_reason:
                                                                                    reason.trim(),
                                                                            },
                                                                            {
                                                                                preserveScroll: true,
                                                                            },
                                                                        );
                                                                    }}
                                                                >
                                                                    Return
                                                                </Button>
                                                            ) : null}
                                                        </div>
                                                    ) : null}
                                                </div>
                                            </TableCell>

                                            {/* Bank account */}
                                            <PayrollRecordBankAccountCell
                                                primary_account={
                                                    row.primary_account ?? null
                                                }
                                                salary_payment_method={
                                                    paymentMethod
                                                }
                                            />

                                            {/* Basic salary */}
                                            <TableCell
                                                className={cn(
                                                    dataTableCellClass(),
                                                    'border-l border-primary/8 bg-primary/2 text-right',
                                                )}
                                            >
                                                <SalaryCell
                                                    value={
                                                        contract?.basic_salary
                                                    }
                                                />
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
                                                    value={
                                                        contract?.site_allowance
                                                    }
                                                />
                                            </TableCell>

                                            {/* Sign-on / Sign-off standby (daily) or Unpaid leave (monthly) */}
                                            <TableCell
                                                className={cn(
                                                    dataTableCellClass(),
                                                    'border-l border-blue-500/8 bg-blue-500/2',
                                                )}
                                            >
                                                {isMonthlyCrewRow ? (
                                                    <div className="flex flex-col gap-1">
                                                        <p className="text-[10px] font-semibold tracking-wide text-muted-foreground/70 uppercase">
                                                            Unpaid leave days
                                                        </p>
                                                        <Input
                                                            type="number"
                                                            min="0"
                                                            step="0.01"
                                                            inputMode="decimal"
                                                            placeholder="0"
                                                            value={
                                                                unpaidLeaveDays
                                                            }
                                                            onChange={(e) =>
                                                                handleCrewTimesheetChange(
                                                                    row.employee
                                                                        .id,
                                                                    'unpaid_leave_days',
                                                                    e.target
                                                                        .value,
                                                                    row.timesheet,
                                                                )
                                                            }
                                                            disabled={
                                                                !canEditTimesheets
                                                            }
                                                            className="h-8 w-[110px] rounded-md border-border/50 bg-background/60 px-2 font-mono text-[11px] tabular-nums shadow-none transition-colors focus:bg-background disabled:cursor-not-allowed disabled:opacity-50"
                                                            aria-label={`Unpaid leave days for ${row.employee.name}`}
                                                        />
                                                    </div>
                                                ) : isOperationallyLocked ? (
                                                    <div className="space-y-2 text-[11px]">
                                                        <OperationalDateRange
                                                            label="Sign-on standby"
                                                            from={
                                                                row.timesheet
                                                                    ?.sign_on_standby_from
                                                            }
                                                            to={
                                                                row.timesheet
                                                                    ?.sign_on_standby_to
                                                            }
                                                            days={
                                                                row.timesheet
                                                                    ?.sign_on_standby_days
                                                            }
                                                        />
                                                        <OperationalDateRange
                                                            label="Sign-off standby"
                                                            from={
                                                                row.timesheet
                                                                    ?.sign_off_standby_from
                                                            }
                                                            to={
                                                                row.timesheet
                                                                    ?.sign_off_standby_to
                                                            }
                                                            days={
                                                                row.timesheet
                                                                    ?.sign_off_standby_days
                                                            }
                                                        />
                                                    </div>
                                                ) : (
                                                    <div className="space-y-2">
                                                        <CrewRangeEditor
                                                            label="Sign-on standby"
                                                            from={signOnFrom}
                                                            to={signOnTo}
                                                            disabled={
                                                                !canEditTimesheets
                                                            }
                                                            onFromChange={(v) =>
                                                                handleCrewTimesheetChange(
                                                                    row.employee
                                                                        .id,
                                                                    'sign_on_standby_from',
                                                                    v,
                                                                    row.timesheet,
                                                                )
                                                            }
                                                            onToChange={(v) =>
                                                                handleCrewTimesheetChange(
                                                                    row.employee
                                                                        .id,
                                                                    'sign_on_standby_to',
                                                                    v,
                                                                    row.timesheet,
                                                                )
                                                            }
                                                        />
                                                        <CrewRangeEditor
                                                            label="Sign-off standby"
                                                            from={signOffFrom}
                                                            to={signOffTo}
                                                            disabled={
                                                                !canEditTimesheets
                                                            }
                                                            onFromChange={(v) =>
                                                                handleCrewTimesheetChange(
                                                                    row.employee
                                                                        .id,
                                                                    'sign_off_standby_from',
                                                                    v,
                                                                    row.timesheet,
                                                                )
                                                            }
                                                            onToChange={(v) =>
                                                                handleCrewTimesheetChange(
                                                                    row.employee
                                                                        .id,
                                                                    'sign_off_standby_to',
                                                                    v,
                                                                    row.timesheet,
                                                                )
                                                            }
                                                        />
                                                    </div>
                                                )}
                                            </TableCell>

                                            {/* Onsite dates */}
                                            <TableCell
                                                className={cn(
                                                    dataTableCellClass(),
                                                    'border-r border-blue-500/8 bg-blue-500/2',
                                                )}
                                            >
                                                {isMonthlyCrewRow ? (
                                                    <span className="text-xs text-muted-foreground">
                                                        —
                                                    </span>
                                                ) : isOperationallyLocked ? (
                                                    <OperationalDateRange
                                                        label="Onsite"
                                                        from={
                                                            row.timesheet
                                                                ?.onsite_from
                                                        }
                                                        to={
                                                            row.timesheet
                                                                ?.onsite_to
                                                        }
                                                        days={
                                                            row.timesheet
                                                                ?.onsite_days
                                                        }
                                                    />
                                                ) : (
                                                    <CrewRangeEditor
                                                        label="Onsite"
                                                        from={onsiteFrom}
                                                        to={onsiteTo}
                                                        disabled={
                                                            !canEditTimesheets
                                                        }
                                                        activeColorClass="border-emerald-500/20 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300"
                                                        onFromChange={(v) =>
                                                            handleCrewTimesheetChange(
                                                                row.employee.id,
                                                                'onsite_from',
                                                                v,
                                                                row.timesheet,
                                                            )
                                                        }
                                                        onToChange={(v) =>
                                                            handleCrewTimesheetChange(
                                                                row.employee.id,
                                                                'onsite_to',
                                                                v,
                                                                row.timesheet,
                                                            )
                                                        }
                                                    />
                                                )}
                                            </TableCell>

                                            {/* Overtime hours */}
                                            <TableCell
                                                className={cn(
                                                    dataTableCellClass(),
                                                    'border-x border-amber-500/8 bg-amber-500/2',
                                                )}
                                            >
                                                {isMonthlyCrewRow ? (
                                                    <span className="text-xs text-muted-foreground">
                                                        —
                                                    </span>
                                                ) : (
                                                    <Input
                                                        type="number"
                                                        min="0"
                                                        step="0.01"
                                                        inputMode="decimal"
                                                        placeholder="0"
                                                        value={overtimeHours}
                                                        onChange={(e) =>
                                                            handleCrewTimesheetChange(
                                                                row.employee.id,
                                                                'overtime_hours',
                                                                e.target.value,
                                                                row.timesheet,
                                                            )
                                                        }
                                                        disabled={
                                                            !canEditTimesheets
                                                        }
                                                        className="h-8 w-[110px] rounded-md border-border/50 bg-background/60 px-2 font-mono text-[11px] tabular-nums shadow-none transition-colors focus:bg-background disabled:cursor-not-allowed disabled:opacity-50"
                                                        aria-label={`Overtime hours for ${row.employee.name}`}
                                                    />
                                                )}
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
                                            <TableCell
                                                className={dataTableCellClass()}
                                            >
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

                        <Pagination
                            {...list.paginationProps}
                            label="employees"
                        />
                    </>
                )}
            </div>
        );
    }

    function renderPayrollTab() {
        const emptyDescription = period.supports_timesheets
            ? 'Generate payroll from entered timesheets to review gross and net amounts.'
            : 'Generate payroll to review full monthly salary and leave usage for this period.';

        if (!hasPayrollRecords) {
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
                        ) : isGenerationBlocked ? (
                            <p className="max-w-md text-center text-sm text-muted-foreground">
                                {generationBlockingReason}
                            </p>
                        ) : undefined
                    }
                />
            );
        }

        const dailyCrewRecords = payroll_records.filter(
            (record): record is CrewPayrollRecordListItem =>
                record.payroll_category === 'crew',
        );
        const monthlyCrewRecords = payroll_records_monthly;
        const officeRecords = payroll_records.filter(
            (record): record is OfficePayrollRecordListItem =>
                record.payroll_category === 'office',
        );

        const payrollRecordsQuery = {
            crew_salary_structure: activeCrewSalaryStructure,
            search: initialSearch || undefined,
        };

        return (
            <>
                {period.supports_timesheets ? (
                    <div className="space-y-6">
                        {activeCrewSalaryStructure === 'daily' ? (
                            dailyCrewRecords.length > 0 ? (
                                <>
                                    <PayrollRecordsTable
                                        records={dailyCrewRecords}
                                        salaryInputsByEmployee={
                                            salary_inputs_by_employee
                                        }
                                        canManageSalaryInputs={
                                            canManageSalaryInputs
                                        }
                                        canRemove={canGenerate}
                                        wpsSelection={wpsSelection}
                                        onManageSalaryInputs={
                                            setSalaryInputsRecord
                                        }
                                        onRemove={setRemoveRecord}
                                        isPayslipGenerationLive={
                                            isPayslipGenerationLive
                                        }
                                    />
                                    {recordsPagination &&
                                    recordsPagination.last_page > 1 ? (
                                        <Pagination
                                            currentPage={
                                                recordsPagination.current_page
                                            }
                                            lastPage={
                                                recordsPagination.last_page
                                            }
                                            perPage={recordsPagination.per_page}
                                            total={recordsPagination.total}
                                            from={recordsPagination.from}
                                            to={recordsPagination.to}
                                            onPageChange={(page) => {
                                                list.visit({
                                                    ...payrollRecordsQuery,
                                                    records_page: page,
                                                    monthly_records_page:
                                                        monthlyRecordsPagination?.current_page ??
                                                        undefined,
                                                });
                                            }}
                                        />
                                    ) : null}
                                </>
                            ) : (
                                <EmptyState
                                    title="No daily crew payroll records"
                                    description="Generate payroll or switch to Monthly to review monthly crew salaries."
                                />
                            )
                        ) : (monthlyRecordsPagination?.total ?? 0) > 0 ? (
                            <>
                                <OfficePayrollRecordsTable
                                    records={monthlyCrewRecords.map(
                                        (record) => ({
                                            ...record,
                                            payroll_category: 'office' as const,
                                        }),
                                    )}
                                    salaryInputsByEmployee={
                                        salary_inputs_by_employee
                                    }
                                    canManageSalaryInputs={
                                        canManageSalaryInputs
                                    }
                                    canRemove={canGenerate}
                                    wpsSelection={wpsSelection}
                                    onManageSalaryInputs={(record) =>
                                        setSalaryInputsRecord({
                                            ...record,
                                            payroll_category: 'crew',
                                        } as CrewPayrollRecordListItem)
                                    }
                                    onRemove={(record) =>
                                        setRemoveRecord({
                                            ...record,
                                            payroll_category: 'crew',
                                        } as CrewPayrollRecordListItem)
                                    }
                                    isPayslipGenerationLive={
                                        isPayslipGenerationLive
                                    }
                                />
                                {monthlyRecordsPagination &&
                                monthlyRecordsPagination.last_page > 1 ? (
                                    <Pagination
                                        currentPage={
                                            monthlyRecordsPagination.current_page
                                        }
                                        lastPage={
                                            monthlyRecordsPagination.last_page
                                        }
                                        perPage={
                                            monthlyRecordsPagination.per_page
                                        }
                                        total={monthlyRecordsPagination.total}
                                        from={monthlyRecordsPagination.from}
                                        to={monthlyRecordsPagination.to}
                                        onPageChange={(page) => {
                                            list.visit({
                                                ...payrollRecordsQuery,
                                                monthly_records_page: page,
                                                records_page:
                                                    recordsPagination?.current_page ??
                                                    undefined,
                                            });
                                        }}
                                    />
                                ) : null}
                            </>
                        ) : (
                            <EmptyState
                                title="No monthly crew payroll records"
                                description="Switch to Daily to review standby, onsite, and overtime payroll."
                            />
                        )}
                    </div>
                ) : (
                    <OfficePayrollRecordsTable
                        records={officeRecords}
                        salaryInputsByEmployee={salary_inputs_by_employee}
                        canManageSalaryInputs={canManageSalaryInputs}
                        canRemove={canGenerate}
                        wpsSelection={wpsSelection}
                        onManageSalaryInputs={setSalaryInputsRecord}
                        onRemove={setRemoveRecord}
                        isPayslipGenerationLive={isPayslipGenerationLive}
                    />
                )}
                {!period.supports_timesheets && recordsPagination ? (
                    <Pagination
                        currentPage={recordsPagination.current_page}
                        lastPage={recordsPagination.last_page}
                        perPage={recordsPagination.per_page}
                        total={recordsPagination.total}
                        from={recordsPagination.from}
                        to={recordsPagination.to}
                        onPageChange={(page) => {
                            list.visit({
                                records_page: page,
                                search: initialSearch || undefined,
                            });
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
    cash_payment: 'Non-bank payment',
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
                title="Non-bank payment"
                value={employee_stats.cash_payment_count}
                subtitle="C3, Ansari, Cash, or third party"
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
                isSelected && 'shadow-lg ring-2 ring-primary/50',
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
                    description={`Only active employees with an active ${period.payroll_category_label.toLowerCase()} contract appear on this pay run.`}
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
                                const isExcluded = excludedIds.has(
                                    row.employee.id,
                                );
                                const paymentMethod =
                                    (row.salary_payment_method ??
                                        'bank_transfer') as SalaryPaymentMethodValue;
                                const contract = row.contract ?? null;
                                const startDate =
                                    rowDates[row.employee.id]?.start ??
                                    period.start_date;
                                const endDate =
                                    rowDates[row.employee.id]?.end ??
                                    period.end_date;
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
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
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
                                            salary_payment_method={
                                                paymentMethod
                                            }
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
                                                value={
                                                    contract?.housing_allowance
                                                }
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
                                                value={
                                                    contract?.transport_allowance
                                                }
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
                                                value={
                                                    contract?.other_allowances
                                                }
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
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
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
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            <Badge
                                                variant="secondary"
                                                className={cn(
                                                    'inline-flex items-center gap-1 rounded-lg border px-2.5 py-1 text-xs font-semibold tabular-nums transition-colors',
                                                    totalDays &&
                                                        Number(totalDays) > 0
                                                        ? 'border-emerald-500/25 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300'
                                                        : 'border-amber-500/25 bg-amber-500/10 text-amber-700 dark:text-amber-300',
                                                )}
                                            >
                                                {formatTimesheetDays(totalDays)}{' '}
                                                days
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

function CrewRangeEditor({
    label,
    from,
    to,
    onFromChange,
    onToChange,
    disabled,
    activeColorClass = 'border-blue-500/20 bg-blue-500/10 text-blue-700 dark:text-blue-300',
}: {
    label: string;
    from: string;
    to: string;
    onFromChange: (value: string) => void;
    onToChange: (value: string) => void;
    disabled: boolean;
    activeColorClass?: string;
}) {
    const days = calculateInclusiveDays(from, to);
    const inputClass =
        'h-7 w-[130px] rounded-md border-border/50 bg-background/60 px-1.5 font-mono text-[11px] shadow-none transition-colors focus:bg-background disabled:cursor-not-allowed disabled:opacity-50 [&::-webkit-calendar-picker-indicator]:cursor-pointer [&::-webkit-calendar-picker-indicator]:opacity-50 hover:[&::-webkit-calendar-picker-indicator]:opacity-90 [&::-webkit-calendar-picker-indicator]:dark:invert';

    return (
        <div className="flex flex-col gap-1">
            <p className="text-[10px] font-semibold tracking-wide text-muted-foreground/70 uppercase">
                {label}
            </p>
            <div className="flex items-center gap-1">
                <Input
                    type="date"
                    value={from}
                    onChange={(e) => onFromChange(e.target.value)}
                    className={inputClass}
                    disabled={disabled}
                />
                <span className="shrink-0 text-[10px] font-bold text-muted-foreground/40">
                    →
                </span>
                <Input
                    type="date"
                    value={to}
                    onChange={(e) => onToChange(e.target.value)}
                    className={inputClass}
                    disabled={disabled}
                />
            </div>
            <Badge
                variant="secondary"
                className={cn(
                    'inline-flex w-fit items-center gap-1 rounded-md px-2 py-0.5 text-[10px] font-bold tabular-nums transition-colors',
                    days && Number(days) > 0
                        ? activeColorClass
                        : 'border-dashed border-border/60 bg-transparent text-muted-foreground/50',
                )}
            >
                {days && Number(days) > 0 ? (
                    <>{formatTimesheetDays(days)} days</>
                ) : (
                    <>No dates set</>
                )}
            </Badge>
        </div>
    );
}

function OperationalDateRange({
    label,
    from,
    to,
    days,
}: {
    label: string;
    from: string | null | undefined;
    to: string | null | undefined;
    days: string | null | undefined;
}) {
    const hasDays = days !== null && days !== undefined && days !== '';
    const hasRange = Boolean(from) || Boolean(to);

    return (
        <div className="space-y-1">
            <p className="text-[10px] font-semibold tracking-wide text-muted-foreground/70 uppercase">
                {label}
            </p>
            {hasRange ? (
                <p className="font-mono text-[11px] text-foreground/90">
                    {formatDisplayDate(from)} → {formatDisplayDate(to)}
                </p>
            ) : (
                <p className="text-[11px] text-muted-foreground/60">
                    No dates set
                </p>
            )}
            {hasDays && Number(days) > 0 ? (
                <Badge
                    variant="secondary"
                    className="inline-flex w-fit items-center gap-1 rounded-md border-blue-500/20 bg-blue-500/10 px-2 py-0.5 text-[10px] font-bold text-blue-700 tabular-nums dark:text-blue-300"
                >
                    {formatTimesheetDays(days)} days
                </Badge>
            ) : null}
        </div>
    );
}
