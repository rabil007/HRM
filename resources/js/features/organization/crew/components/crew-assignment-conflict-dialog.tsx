import { router } from '@inertiajs/react';
import { AlertCircle, Calendar, ExternalLink, Ship } from 'lucide-react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { show as showAssignment } from '@/routes/organization/crew-assignments';
import type { CrewAssignmentPagePermissions } from '../types';

export type ConflictDialogData = {
    has_conflict: boolean;
    severity: 'error' | 'warning';
    code: string;
    message: string;
    existing_assignment?: {
        id: number;
        assignment_no: string;
        status: string;
        status_label: string;
        vessel_id: number | null;
        vessel_name: string | null;
        rank_id: number | null;
        rank_name: string | null;
        planned_join_at: string | null;
        planned_signoff_at: string | null;
        current_phase_code: string | null;
        current_phase_label: string | null;
    } | null;
    new_assignment?: {
        vessel_id: number | null;
        vessel_name: string | null;
        rank_id: number | null;
        rank_name: string | null;
        planned_join_at: string | null;
        planned_signoff_at: string | null;
    } | null;
    affected_dates?: {
        overlap_start: string | null;
        overlap_end: string | null;
        existing_start: string | null;
        existing_end: string | null;
        new_start: string | null;
        new_end: string | null;
    } | null;
    allowed_actions: string[];
    blocking: boolean;
};

export type CrewAssignmentConflictDialogProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    conflict: ConflictDialogData | null;
    employeeName?: string | null;
    can: CrewAssignmentPagePermissions;
    onAdjustDates?: () => void;
};

function formatDate(dateStr: string | null | undefined): string {
    if (!dateStr) {
        return '—';
    }

    const d = new Date(`${dateStr}T00:00:00`);

    if (isNaN(d.getTime())) {
        return dateStr;
    }

    return d.toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export function CrewAssignmentConflictDialog({
    open,
    onOpenChange,
    conflict,
    employeeName,
    can,
    onAdjustDates,
}: CrewAssignmentConflictDialogProps): ReactElement | null {
    if (!conflict) {
        return null;
    }

    const {
        code,
        message,
        existing_assignment: existing,
        new_assignment: next,
        affected_dates: dates,
        allowed_actions: allowedActions = [],
    } = conflict;

    const isPlannedPlanned = code === 'planned_planned_overlap';
    const isActiveConflict =
        code === 'active_planned_overlap' ||
        code === 'active_assignment_exists' ||
        code === 'active_active_incompatible';

    const handleAdjustDates = (): void => {
        onOpenChange(false);
        onAdjustDates?.();
    };

    const handleViewExisting = (): void => {
        if (!existing?.id) {
            return;
        }

        onOpenChange(false);
        router.visit(showAssignment.url(existing.id));
    };

    const handleEditExistingPlan = (): void => {
        if (!existing?.id) {
            return;
        }

        onOpenChange(false);
        router.visit(showAssignment.url(existing.id));
    };

    const handleCancelExistingPlan = (): void => {
        if (!existing?.id) {
            return;
        }

        onOpenChange(false);
        router.visit(
            showAssignment.url(existing.id, {
                query: { action: 'cancel_assignment' },
            }),
        );
    };

    const handleTransferVessel = (): void => {
        if (!existing?.id) {
            return;
        }

        onOpenChange(false);
        router.visit(
            showAssignment.url(existing.id, {
                query: { action: 'transfer_vessel' },
            }),
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <div className="flex items-center gap-2.5 text-destructive">
                        <AlertCircle className="h-5 w-5" />
                        <DialogTitle className="text-base font-semibold">
                            Crew Assignment Conflict
                            {employeeName ? ` — ${employeeName}` : ''}
                        </DialogTitle>
                    </div>
                    <DialogDescription className="text-sm text-muted-foreground">
                        {message}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-2 text-sm">
                    {/* Existing assignment card */}
                    {existing ? (
                        <div className="space-y-1.5 rounded-xl border border-border/80 bg-muted/30 p-3.5">
                            <div className="flex items-center justify-between text-xs font-medium text-muted-foreground">
                                <span>
                                    {isPlannedPlanned
                                        ? 'Existing Planned Assignment'
                                        : 'Current Operational Assignment'}
                                </span>
                                <span className="rounded-md border border-border bg-background px-2 py-0.5 font-mono text-[11px]">
                                    {existing.assignment_no}
                                </span>
                            </div>
                            <div className="flex items-center gap-2 font-medium text-foreground">
                                <Ship className="h-4 w-4 text-muted-foreground" />
                                <span>
                                    {existing.vessel_name ?? 'No vessel'}
                                </span>
                                {existing.rank_name ? (
                                    <span className="text-xs text-muted-foreground">
                                        • {existing.rank_name}
                                    </span>
                                ) : null}
                            </div>
                            {existing.current_phase_label ? (
                                <p className="text-xs text-muted-foreground">
                                    Current phase:{' '}
                                    <span className="font-medium text-foreground">
                                        {existing.current_phase_label}
                                    </span>
                                </p>
                            ) : null}
                            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                <Calendar className="h-3.5 w-3.5" />
                                <span>
                                    {formatDate(
                                        dates?.existing_start ??
                                            existing.planned_join_at,
                                    )}{' '}
                                    –{' '}
                                    {formatDate(
                                        dates?.existing_end ??
                                            existing.planned_signoff_at,
                                    )}
                                </span>
                            </div>
                        </div>
                    ) : null}

                    {/* New assignment card */}
                    {next ? (
                        <div className="space-y-1.5 rounded-xl border border-primary/20 bg-primary/5 p-3.5">
                            <div className="text-xs font-medium text-primary">
                                New Requested Assignment
                            </div>
                            <div className="flex items-center gap-2 font-medium text-foreground">
                                <Ship className="h-4 w-4 text-primary" />
                                <span>{next.vessel_name ?? 'No vessel'}</span>
                                {next.rank_name ? (
                                    <span className="text-xs text-muted-foreground">
                                        • {next.rank_name}
                                    </span>
                                ) : null}
                            </div>
                            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                <Calendar className="h-3.5 w-3.5" />
                                <span>
                                    {formatDate(
                                        dates?.new_start ??
                                            next.planned_join_at,
                                    )}{' '}
                                    –{' '}
                                    {formatDate(
                                        dates?.new_end ??
                                            next.planned_signoff_at,
                                    )}
                                </span>
                            </div>
                        </div>
                    ) : null}

                    {/* Overlap range callout */}
                    {dates?.overlap_start ? (
                        <div className="flex items-center gap-2 rounded-lg bg-destructive/10 px-3 py-2 text-xs font-medium text-destructive">
                            <span>
                                Overlap period:{' '}
                                {formatDate(dates.overlap_start)} –{' '}
                                {formatDate(dates.overlap_end)}
                            </span>
                        </div>
                    ) : null}
                </div>

                <DialogFooter className="flex-wrap gap-2 sm:justify-end">
                    {/* Adjust dates */}
                    {allowedActions.includes('adjust_dates') ||
                    allowedActions.includes('reschedule') ? (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={handleAdjustDates}
                        >
                            {isPlannedPlanned
                                ? 'Adjust New Dates'
                                : 'Reschedule'}
                        </Button>
                    ) : null}

                    {/* Edit existing plan */}
                    {isPlannedPlanned &&
                    allowedActions.includes('edit_existing_plan') &&
                    can.update ? (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={handleEditExistingPlan}
                        >
                            <ExternalLink className="mr-1.5 h-3.5 w-3.5" />
                            Edit Existing Plan
                        </Button>
                    ) : null}

                    {/* Cancel existing plan */}
                    {isPlannedPlanned &&
                    allowedActions.includes('cancel_existing_plan') &&
                    can.cancel ? (
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={handleCancelExistingPlan}
                        >
                            Cancel Existing Plan
                        </Button>
                    ) : null}

                    {/* View current assignment */}
                    {isActiveConflict &&
                    allowedActions.includes('view_current_assignment') &&
                    can.view ? (
                        <Button
                            type="button"
                            variant="outline"
                            onClick={handleViewExisting}
                        >
                            <ExternalLink className="mr-1.5 h-3.5 w-3.5" />
                            View Current Assignment
                        </Button>
                    ) : null}

                    {/* Transfer vessel if supported */}
                    {isActiveConflict &&
                    allowedActions.includes('transfer_vessel') &&
                    can.perform_movement ? (
                        <Button
                            type="button"
                            variant="default"
                            onClick={handleTransferVessel}
                        >
                            Transfer Vessel
                        </Button>
                    ) : null}

                    {/* Cancel / Close dialog */}
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
