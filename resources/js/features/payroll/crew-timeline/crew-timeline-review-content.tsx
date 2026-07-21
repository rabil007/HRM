import { router } from '@inertiajs/react';
import {
    CheckCircle2,
    FileSpreadsheet,
    RotateCcw,
    Send,
    Ship,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import PrepareCrewTimesheetTimelineController from '@/actions/App/Http/Controllers/Payroll/PrepareCrewTimesheetTimelineController';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { formatDisplayDate, formatDisplayDateTime } from '@/lib/format-date';
import { show as payrollShow } from '@/routes/payroll';
import { CrewTimelineApplyDialog } from './crew-timeline-apply-dialog';
import { CrewTimelineApproveDialog } from './crew-timeline-approve-dialog';
import { CrewTimelineEmployeeTable } from './crew-timeline-employee-table';
import { CrewTimelineReturnDialog } from './crew-timeline-return-dialog';
import { CrewTimelineStatusBadge } from './crew-timeline-status-badge';
import { CrewTimelineSubmitDialog } from './crew-timeline-submit-dialog';
import { CrewTimelineSummaryCards } from './crew-timeline-summary-cards';
import { CrewTimelineWarningPanel } from './crew-timeline-warning-panel';
import { CrewTimelineWorkflowSteps } from './crew-timeline-workflow-steps';
import type {
    CrewTimelineShowProps,
    CrewTimelineWarningBreakdownItem,
} from './types';

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
    employees,
    permissions,
}: CrewTimelineShowProps) {
    const [submitOpen, setSubmitOpen] = useState(false);
    const [approveOpen, setApproveOpen] = useState(false);
    const [returnOpen, setReturnOpen] = useState(false);
    const [applyOpen, setApplyOpen] = useState(false);
    const [isPreparing, setIsPreparing] = useState(false);

    const warningBreakdown = useMemo<CrewTimelineWarningBreakdownItem[]>(() => {
        const byCode = new Map<string, CrewTimelineWarningBreakdownItem>();

        for (const employee of employees) {
            for (const line of employee.lines) {
                if (!line.warning) {
                    continue;
                }

                const existing = byCode.get(line.warning.code);

                if (existing) {
                    existing.count += 1;
                } else {
                    byCode.set(line.warning.code, {
                        code: line.warning.code,
                        label: line.warning.label,
                        is_blocking: line.warning.is_blocking,
                        count: 1,
                    });
                }
            }
        }

        return Array.from(byCode.values()).sort((a, b) => b.count - a.count);
    }, [employees]);

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
        summary.blocking_warning_count === 0 &&
        period.status === 'draft';

    const canApprove =
        permissions.approve &&
        preparation.status === 'submitted' &&
        preparation.is_fresh &&
        summary.blocking_warning_count === 0 &&
        period.status === 'draft';

    const canReturn =
        permissions.return &&
        preparation.status === 'submitted' &&
        period.status === 'draft';

    const canApply =
        permissions.apply &&
        preparation.status === 'approved' &&
        preparation.is_fresh &&
        summary.blocking_warning_count === 0 &&
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
                    breakdown={warningBreakdown}
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
                    <CardContent className="grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-3">
                        <Meta
                            label="Cutoff date"
                            value={formatDisplayDate(preparation.cutoff_date)}
                        />
                        <Meta
                            label="Source freshness"
                            value={
                                preparation.is_fresh
                                    ? 'Fresh'
                                    : 'Timeline changed'
                            }
                        />
                        <Meta
                            label="Prepared"
                            value={actorLabel(
                                preparation.prepared_by,
                                preparation.prepared_at,
                            )}
                        />
                        <Meta
                            label="Submitted"
                            value={actorLabel(
                                preparation.submitted_by,
                                preparation.submitted_at,
                            )}
                        />
                        <Meta
                            label="Approved"
                            value={actorLabel(
                                preparation.approved_by,
                                preparation.approved_at,
                            )}
                        />
                        <Meta
                            label="Returned"
                            value={actorLabel(
                                preparation.returned_by,
                                preparation.returned_at,
                            )}
                        />
                        <Meta
                            label="Applied"
                            value={actorLabel(
                                preparation.applied_by,
                                preparation.applied_at,
                            )}
                        />
                        <Meta
                            label="Linked timesheets"
                            value={String(preparation.linked_timesheet_count)}
                        />
                    </CardContent>
                </Card>

                <CrewTimelineSummaryCards summary={summary} />

                <div className="space-y-3">
                    <div className="flex items-center gap-3">
                        <div className="h-px flex-1 bg-border/60" />
                        <span className="text-[11px] font-bold tracking-widest text-muted-foreground/50 uppercase">
                            Employee Breakdown
                        </span>
                        <div className="h-px flex-1 bg-border/60" />
                    </div>
                    <CrewTimelineEmployeeTable employees={employees} />
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
            <p className="text-xs tracking-wide text-muted-foreground uppercase">
                {label}
            </p>
            <p className="text-sm font-medium">{value}</p>
        </div>
    );
}
