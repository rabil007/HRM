import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    CheckCircle2,
    FileText,
    Ship,
    UserCheck,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { CrewMobilisationReadinessBadge } from '@/features/organization/crew/components/crew-mobilisation-readiness-badge';
import { formatJoinTimeline } from '@/features/organization/crew-readiness/lib/crew-readiness-query';
import type {
    CrewReadinessCheck,
    CrewReadinessRow,
} from '@/features/organization/crew-readiness/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

export function CrewReadinessDetailSheet({
    row,
    open,
    onOpenChange,
}: {
    row: CrewReadinessRow | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [submittingMobilisation, setSubmittingMobilisation] = useState(false);

    if (!row) {
        return null;
    }

    const timeline = formatJoinTimeline(row.days_until_join, row.is_overdue);

    const handleStartMobilisation = () => {
        if (!row.start_mobilisation_href || submittingMobilisation) {
            return;
        }

        setSubmittingMobilisation(true);
        router.post(
            row.start_mobilisation_href,
            {},
            {
                onFinish: () => setSubmittingMobilisation(false),
            },
        );
    };

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent className="flex w-full flex-col gap-0 p-0 sm:max-w-lg">
                <SheetHeader className="border-b px-6 py-4">
                    <div className="flex items-start justify-between gap-3">
                        <div className="space-y-1">
                            <SheetTitle className="text-base font-semibold">
                                {row.employee.name}
                            </SheetTitle>
                            <SheetDescription className="text-xs">
                                {row.employee.employee_no
                                    ? `${row.employee.employee_no} • `
                                    : ''}
                                {row.position?.title ?? 'No rank'}
                            </SheetDescription>
                        </div>
                        <CrewMobilisationReadinessBadge
                            readiness={row.readiness}
                        />
                    </div>
                </SheetHeader>

                <div className="flex-1 space-y-6 overflow-y-auto px-6 py-4">
                    {/* Operational Context Card */}
                    <div className="space-y-3 rounded-xl border bg-card/60 p-4 shadow-2xs">
                        <div className="flex items-center justify-between text-xs">
                            <span className="font-medium text-muted-foreground">
                                Source & Stage
                            </span>
                            <div className="flex items-center gap-1.5">
                                <Badge
                                    variant="secondary"
                                    className="text-[11px] font-medium"
                                >
                                    {row.source_label}
                                </Badge>
                                <Badge
                                    variant="outline"
                                    className="text-[11px]"
                                >
                                    {row.stage_label}
                                </Badge>
                            </div>
                        </div>

                        <div className="flex items-center justify-between text-xs">
                            <span className="font-medium text-muted-foreground">
                                Assigned Vessel
                            </span>
                            <span className="flex items-center gap-1 font-semibold text-foreground">
                                <Ship className="h-3.5 w-3.5 text-muted-foreground" />
                                {row.vessel?.name ?? '—'}
                            </span>
                        </div>

                        <div className="grid grid-cols-3 gap-2 border-t pt-3 text-center text-xs">
                            <div>
                                <p className="text-[11px] text-muted-foreground">
                                    Expected Arrival
                                </p>
                                <p className="mt-0.5 font-medium">
                                    {formatDisplayDate(
                                        row.expected_arrival_date,
                                    )}
                                </p>
                            </div>
                            <div className="border-x px-1">
                                <p className="text-[11px] text-muted-foreground">
                                    Expected Join
                                </p>
                                <p className="mt-0.5 font-semibold text-foreground">
                                    {formatDisplayDate(row.expected_join_date)}
                                </p>
                                {timeline ? (
                                    <p
                                        className={cn(
                                            'mt-0.5 text-[10px]',
                                            timeline.tone,
                                        )}
                                    >
                                        {timeline.label}
                                    </p>
                                ) : null}
                            </div>
                            <div>
                                <p className="text-[11px] text-muted-foreground">
                                    Expected Sign-Off
                                </p>
                                <p className="mt-0.5 font-medium">
                                    {formatDisplayDate(
                                        row.expected_signoff_date,
                                    )}
                                </p>
                            </div>
                        </div>
                    </div>

                    {/* Checks and Compliance Section */}
                    <div className="space-y-3">
                        <div className="flex items-center justify-between">
                            <h4 className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                Configured Checks ({row.readiness.checks_clear}{' '}
                                / {row.readiness.checks_total} clear)
                            </h4>
                            <span className="text-[11px] font-medium text-muted-foreground">
                                {row.readiness.status_label}
                            </span>
                        </div>

                        {row.readiness.checks.length === 0 ? (
                            <div className="rounded-xl border border-dashed p-4 text-center text-xs text-muted-foreground">
                                No required document checks are configured for
                                this employee in this company.
                            </div>
                        ) : (
                            <div className="space-y-2">
                                {row.readiness.checks.map(
                                    (
                                        check: CrewReadinessCheck,
                                        idx: number,
                                    ) => {
                                        const isOk = check.severity === 'ok';
                                        const isWarning =
                                            check.severity === 'warning';
                                        const isCritical =
                                            check.severity === 'critical';

                                        return (
                                            <div
                                                key={`${check.code}-${idx}`}
                                                className={cn(
                                                    'flex items-start gap-3 rounded-lg border p-3 text-xs transition-colors',
                                                    isOk &&
                                                        'border-emerald-500/20 bg-emerald-500/5',
                                                    isWarning &&
                                                        'border-amber-500/30 bg-amber-500/5',
                                                    isCritical &&
                                                        'border-red-500/30 bg-red-500/5',
                                                )}
                                            >
                                                <div className="mt-0.5 shrink-0">
                                                    {isOk ? (
                                                        <CheckCircle2 className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                                                    ) : isWarning ? (
                                                        <AlertTriangle className="h-4 w-4 text-amber-600 dark:text-amber-400" />
                                                    ) : (
                                                        <XCircle className="h-4 w-4 text-red-600 dark:text-red-400" />
                                                    )}
                                                </div>
                                                <div className="min-w-0 flex-1 space-y-0.5">
                                                    <div className="flex items-center justify-between gap-2">
                                                        <p className="truncate font-semibold text-foreground">
                                                            {check.label}
                                                        </p>
                                                        <Badge
                                                            variant="outline"
                                                            className={cn(
                                                                'h-4.5 shrink-0 px-1.5 py-0 text-[10px] font-medium',
                                                                isOk &&
                                                                    'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
                                                                isWarning &&
                                                                    'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300',
                                                                isCritical &&
                                                                    'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-300',
                                                            )}
                                                        >
                                                            {isOk
                                                                ? 'Valid'
                                                                : isWarning
                                                                  ? 'Expiring'
                                                                  : 'Attention'}
                                                        </Badge>
                                                    </div>
                                                    <p className="text-[11px] leading-relaxed text-muted-foreground">
                                                        {check.message}
                                                    </p>
                                                </div>
                                            </div>
                                        );
                                    },
                                )}
                            </div>
                        )}

                        <p className="text-[11px] text-muted-foreground italic">
                            {row.readiness.advisory_note}
                        </p>
                    </div>
                </div>

                <SheetFooter className="flex flex-row flex-wrap items-center justify-between gap-2 border-t bg-muted/20 px-6 py-3">
                    <div className="flex items-center gap-2">
                        {row.can_view_plan && row.plan_href ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="h-8 gap-1.5 text-xs"
                                onClick={() => router.visit(row.plan_href!)}
                            >
                                <Calendar className="h-3.5 w-3.5" />
                                Open Crew Plan
                            </Button>
                        ) : null}

                        {row.can_start_mobilisation &&
                        row.start_mobilisation_href ? (
                            <Button
                                type="button"
                                size="sm"
                                className="h-8 gap-1.5 bg-emerald-600 text-xs text-white hover:bg-emerald-700"
                                disabled={submittingMobilisation}
                                onClick={handleStartMobilisation}
                            >
                                <UserCheck className="h-3.5 w-3.5" />
                                {submittingMobilisation
                                    ? 'Starting...'
                                    : 'Start Mobilisation'}
                            </Button>
                        ) : null}

                        {row.can_view_assignment && row.assignment_href ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="h-8 gap-1.5 text-xs"
                                onClick={() =>
                                    router.visit(row.assignment_href!)
                                }
                            >
                                Open Assignment
                            </Button>
                        ) : null}

                        {row.readiness.documents_href ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                className="h-8 gap-1.5 text-xs text-muted-foreground hover:text-foreground"
                                onClick={() =>
                                    router.visit(row.readiness.documents_href!)
                                }
                            >
                                <FileText className="h-3.5 w-3.5" />
                                Open Documents
                            </Button>
                        ) : null}
                    </div>

                    <Button
                        type="button"
                        variant="secondary"
                        size="sm"
                        className="h-8 text-xs"
                        onClick={() => onOpenChange(false)}
                    >
                        Close
                    </Button>
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}
