import { router } from '@inertiajs/react';
import {
    Calendar,
    CheckCircle2,
    FileSpreadsheet,
    Loader2,
    RefreshCw,
    RotateCcw,
    Send,
    Ship,
    Zap,
} from 'lucide-react';
import type React from 'react';
import { useState } from 'react';
import PrepareCrewTimesheetTimelineController from '@/actions/App/Http/Controllers/Payroll/PrepareCrewTimesheetTimelineController';
import { DetailsHeader } from '@/components/details-header';
import { EmptyState } from '@/components/empty-state';
import { Main } from '@/components/layout/main';
import { SearchBar } from '@/components/search-bar';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DepartmentFilterControls } from '@/features/organization/employees/components/department-filter-controls';
import { formatDisplayDate, formatDisplayDateTime } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { show as payrollShow } from '@/routes/payroll';
import { show as crewTimelineShow } from '@/routes/payroll/crew-timeline';
import { CrewTimelineApplyDialog } from './crew-timeline-apply-dialog';
import { CrewTimelineApproveDialog } from './crew-timeline-approve-dialog';
import { CrewTimelineEmployeeTable } from './crew-timeline-employee-table';
import { CrewTimelineReturnDialog } from './crew-timeline-return-dialog';
import { CrewTimelineStatusBadge } from './crew-timeline-status-badge';
import { CrewTimelineSubmitDialog } from './crew-timeline-submit-dialog';
import { CrewTimelineSummaryCards } from './crew-timeline-summary-cards';
import { CrewTimelineWarningPanel } from './crew-timeline-warning-panel';
import { CrewTimelineWorkflowSteps } from './crew-timeline-workflow-steps';
import type { CrewTimelineShowProps } from './types';
import { useCrewTimelineFilters } from './use-crew-timeline-filters';

function actorLabel(user: { name: string } | null, at: string | null): string {
    if (!user && !at) {
        return '—';
    }

    const name = user?.name ?? 'Unknown';
    const when = at ? formatDisplayDateTime(at) : '—';

    return `${name} · ${when}`;
}

export function CrewTimelineReviewContent({
    period,
    preparation,
    summary,
    warning_breakdown,
    employees,
    search: initialSearch,
    filters,
    department_tree,
    department_tree_selected_id,
    department_tree_selected_position_id,
    permissions,
}: CrewTimelineShowProps) {
    const [submitOpen, setSubmitOpen] = useState(false);
    const [approveOpen, setApproveOpen] = useState(false);
    const [returnOpen, setReturnOpen] = useState(false);
    const [applyOpen, setApplyOpen] = useState(false);
    const [isPreparing, setIsPreparing] = useState(false);

    const {
        searchInput,
        onSearchChange,
        onDepartmentChange,
        onPositionChange,
        onSummaryChange,
    } = useCrewTimelineFilters({
        url: crewTimelineShow.url([period.id, preparation.id]),
        initialSearch,
        filters,
    });

    const filtersActive = Boolean(
        initialSearch ||
        filters.department_id ||
        filters.position_id ||
        filters.summary,
    );

    const departmentTreeSelectionCount =
        filters.department_id || filters.position_id ? 1 : 0;

    const canPrepareNewVersion =
        permissions.prepare &&
        period.status === 'draft' &&
        preparation.status !== 'applied' &&
        (preparation.status === 'draft' ||
            preparation.status === 'returned' ||
            preparation.is_stale);

    const canSubmit =
        permissions.submit &&
        preparation.status === 'draft' &&
        preparation.is_latest &&
        preparation.is_fresh &&
        summary.unresolved_blocking_warning_count === 0 &&
        period.status === 'draft';

    const canApprove =
        permissions.approve &&
        preparation.status === 'submitted' &&
        preparation.is_fresh &&
        summary.unresolved_blocking_warning_count === 0 &&
        period.status === 'draft';

    const canReturn =
        permissions.return &&
        preparation.status === 'submitted' &&
        period.status === 'draft';

    const canApply =
        permissions.apply &&
        preparation.status === 'approved' &&
        preparation.is_fresh &&
        summary.unresolved_blocking_warning_count === 0 &&
        period.status === 'draft';

    const prepareNewVersion = (): void => {
        setIsPreparing(true);
        router.post(
            PrepareCrewTimesheetTimelineController.url(period.id),
            {},
            {
                preserveScroll: true,
                onFinish: () => setIsPreparing(false),
            },
        );
    };

    return (
        <Main>
            <DetailsHeader
                kicker="Crew Timeline"
                title={
                    <span className="inline-flex flex-wrap items-center gap-3">
                        {period.name}
                        <CrewTimelineStatusBadge
                            status={preparation.status}
                            label={preparation.status_label}
                        />
                        <span className="text-base font-normal text-muted-foreground">
                            Version {preparation.version}
                        </span>
                    </span>
                }
                description={`${formatDisplayDate(period.start_date)} — ${formatDisplayDate(period.end_date)}`}
                backHref={payrollShow.url(period.id)}
                backLabel="Back to Pay Period"
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {canPrepareNewVersion ? (
                            <Button
                                variant="outline"
                                disabled={isPreparing}
                                onClick={prepareNewVersion}
                            >
                                <Ship className="mr-2 h-4 w-4" />
                                {isPreparing
                                    ? 'Preparing…'
                                    : 'Prepare New Version'}
                            </Button>
                        ) : null}
                        {canSubmit ? (
                            <Button onClick={() => setSubmitOpen(true)}>
                                <Send className="mr-2 h-4 w-4" />
                                Submit for Crewing Approval
                            </Button>
                        ) : null}
                        {canReturn ? (
                            <Button
                                variant="destructive"
                                onClick={() => setReturnOpen(true)}
                            >
                                <RotateCcw className="mr-2 h-4 w-4" />
                                Return
                            </Button>
                        ) : null}
                        {canApprove ? (
                            <Button onClick={() => setApproveOpen(true)}>
                                <CheckCircle2 className="mr-2 h-4 w-4" />
                                Approve
                            </Button>
                        ) : null}
                        {canApply ? (
                            <Button onClick={() => setApplyOpen(true)}>
                                <FileSpreadsheet className="mr-2 h-4 w-4" />
                                Apply Approved Timeline to Timesheets
                            </Button>
                        ) : null}
                    </div>
                }
            />

            <div className="mt-6 space-y-6">
                <CrewTimelineWorkflowSteps
                    status={preparation.status}
                    isReturned={preparation.status === 'returned'}
                />

                <CrewTimelineWarningPanel
                    summary={summary}
                    isStale={preparation.is_stale}
                    breakdown={warning_breakdown}
                />

                {preparation.status === 'approved' ? (
                    <Alert>
                        <CheckCircle2 className="h-4 w-4" />
                        <AlertTitle>Approved</AlertTitle>
                        <AlertDescription>
                            This timeline is approved. Apply it to write
                            operational day totals into crew timesheets while
                            preserving overtime and other financial inputs.
                        </AlertDescription>
                    </Alert>
                ) : null}

                {preparation.status === 'applied' ? (
                    <Alert>
                        <FileSpreadsheet className="h-4 w-4" />
                        <AlertTitle>Applied</AlertTitle>
                        <AlertDescription>
                            Operational timesheets were written from Crew
                            Operations. Linked timesheets:{' '}
                            {preparation.linked_timesheet_count}. Operational
                            fields are locked; financial fields remain editable.
                        </AlertDescription>
                    </Alert>
                ) : null}

                {preparation.status === 'returned' &&
                preparation.decision_notes ? (
                    <Alert>
                        <RotateCcw className="h-4 w-4" />
                        <AlertTitle>Return notes</AlertTitle>
                        <AlertDescription>
                            {preparation.decision_notes}
                        </AlertDescription>
                    </Alert>
                ) : null}

                <Card className="glass-card">
                    <CardHeader className="px-5 pt-5 pb-3">
                        <CardTitle className="text-sm font-semibold text-muted-foreground">
                            Preparation Details
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 px-5 pb-5 sm:grid-cols-2 xl:grid-cols-3">
                        <MetaWithIcon
                            label="Cutoff date"
                            value={formatDisplayDate(preparation.cutoff_date)}
                            icon={Calendar}
                        />
                        <MetaFreshness isFresh={preparation.is_fresh} />
                        {preparation.linked_timesheet_count > 0 ? (
                            <Meta
                                label="Linked timesheets"
                                value={String(
                                    preparation.linked_timesheet_count,
                                )}
                            />
                        ) : null}
                        {preparation.prepared_by ? (
                            <Meta
                                label="Prepared"
                                value={actorLabel(
                                    preparation.prepared_by,
                                    preparation.prepared_at,
                                )}
                            />
                        ) : null}
                        {preparation.submitted_by ? (
                            <Meta
                                label="Submitted"
                                value={actorLabel(
                                    preparation.submitted_by,
                                    preparation.submitted_at,
                                )}
                            />
                        ) : null}
                        {preparation.approved_by ? (
                            <Meta
                                label="Approved"
                                value={actorLabel(
                                    preparation.approved_by,
                                    preparation.approved_at,
                                )}
                            />
                        ) : null}
                        {preparation.returned_by ? (
                            <Meta
                                label="Returned"
                                value={actorLabel(
                                    preparation.returned_by,
                                    preparation.returned_at,
                                )}
                            />
                        ) : null}
                        {preparation.applied_by ? (
                            <Meta
                                label="Applied"
                                value={actorLabel(
                                    preparation.applied_by,
                                    preparation.applied_at,
                                )}
                            />
                        ) : null}
                    </CardContent>
                </Card>

                <CrewTimelineSummaryCards
                    summary={summary}
                    activeFilter={filters.summary || ''}
                    onSelect={onSummaryChange}
                />

                <div className="space-y-3">
                    <div className="flex items-center gap-3">
                        <div className="h-px flex-1 bg-border/40" />
                        <span className="inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-muted/40 px-3 py-0.5 text-[11px] font-semibold tracking-wider text-muted-foreground/70 uppercase">
                            <span className="size-1.5 rounded-full bg-primary/60" />
                            Employee Breakdown
                        </span>
                        <div className="h-px flex-1 bg-border/40" />
                    </div>

                    <SearchBar
                        placeholder="Search employee, assignment or vessel..."
                        value={searchInput}
                        onChange={onSearchChange}
                        className="mb-4"
                        right={
                            <div className="flex items-center gap-3">
                                {searchInput !== initialSearch ? (
                                    <Loader2
                                        className="size-4 animate-spin text-muted-foreground"
                                        aria-hidden
                                    />
                                ) : null}
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
                                    onSelectDepartment={onDepartmentChange}
                                    onSelectPosition={onPositionChange}
                                />
                            </div>
                        }
                    />

                    {employees.length === 0 ? (
                        <EmptyState
                            title={
                                filtersActive
                                    ? 'No matching employees'
                                    : 'No preparation lines were generated.'
                            }
                            description={
                                filtersActive
                                    ? 'Try adjusting your search, department, or summary filter.'
                                    : undefined
                            }
                        />
                    ) : (
                        <CrewTimelineEmployeeTable
                            employees={employees}
                            period={period}
                            periodId={period.id}
                            preparationId={preparation.id}
                        />
                    )}
                </div>
            </div>

            <CrewTimelineSubmitDialog
                open={submitOpen}
                onOpenChange={setSubmitOpen}
                periodId={period.id}
                preparationId={preparation.id}
            />
            <CrewTimelineApproveDialog
                open={approveOpen}
                onOpenChange={setApproveOpen}
                periodId={period.id}
                preparationId={preparation.id}
            />
            <CrewTimelineReturnDialog
                open={returnOpen}
                onOpenChange={setReturnOpen}
                periodId={period.id}
                preparationId={preparation.id}
            />
            <CrewTimelineApplyDialog
                open={applyOpen}
                onOpenChange={setApplyOpen}
                periodId={period.id}
                preparationId={preparation.id}
            />
        </Main>
    );
}

function Meta({ label, value }: { label: string; value: string }) {
    return (
        <div className="space-y-1">
            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground/70 uppercase">
                {label}
            </p>
            <p className="text-sm font-medium">{value}</p>
        </div>
    );
}

function MetaWithIcon({
    label,
    value,
    icon: Icon,
}: {
    label: string;
    value: string;
    icon: React.ComponentType<{ className?: string }>;
}) {
    return (
        <div className="space-y-1">
            <p className="flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground/70 uppercase">
                <Icon className="size-3 shrink-0" />
                {label}
            </p>
            <p className="text-sm font-medium">{value}</p>
        </div>
    );
}

function MetaFreshness({ isFresh }: { isFresh: boolean }) {
    return (
        <div className="space-y-1">
            <p className="flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-muted-foreground/70 uppercase">
                <RefreshCw className="size-3 shrink-0" />
                Source freshness
            </p>
            <p
                className={cn(
                    'inline-flex items-center gap-1.5 text-sm font-semibold',
                    isFresh
                        ? 'text-emerald-600 dark:text-emerald-400'
                        : 'text-amber-600 dark:text-amber-400',
                )}
            >
                {isFresh ? (
                    <Zap className="size-3.5 shrink-0" />
                ) : (
                    <RefreshCw className="size-3.5 shrink-0" />
                )}
                {isFresh ? 'Fresh' : 'Timeline changed'}
            </p>
        </div>
    );
}
