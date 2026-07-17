import { AlertTriangle } from 'lucide-react';
import type { ReactElement } from 'react';
import { useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CrewMetadataField } from '@/features/organization/crew/components/crew-metadata-field';
import { formatDisplayDate, formatDisplayDateTime12h } from '@/lib/format-date';
import { show as showAssignment } from '@/routes/organization/crew-assignments';
import { index as correctionsIndex } from '@/routes/organization/crew-movement-corrections';
import { CorrectionValuesTable } from './components/correction-values-table';
import type { CorrectionDecisionMode } from './components/crew-movement-correction-decision-dialog';
import { CrewMovementCorrectionDecisionDialog } from './components/crew-movement-correction-decision-dialog';
import { CrewMovementCorrectionSlaBadge } from './components/crew-movement-correction-sla-badge';
import { CrewMovementCorrectionStatusBadge } from './components/crew-movement-correction-status-badge';
import type { CrewMovementCorrectionShowProps } from './types';

export function CrewMovementCorrectionShowContent({
    correction,
}: CrewMovementCorrectionShowProps): ReactElement {
    const [decisionMode, setDecisionMode] =
        useState<CorrectionDecisionMode | null>(null);
    const [dialogOpen, setDialogOpen] = useState(false);

    const openDecision = (mode: CorrectionDecisionMode): void => {
        setDecisionMode(mode);
        setDialogOpen(true);
    };

    const fields = Object.keys(correction.proposed_values);

    return (
        <Main>
            <DetailsHeader
                kicker="Movement Corrections"
                title={`Correction #${correction.id}`}
                description={
                    <span className="inline-flex flex-wrap items-center gap-2">
                        <CrewMovementCorrectionStatusBadge
                            status={correction.status}
                            label={correction.status_label}
                        />
                        {correction.assignment ? (
                            <span className="text-muted-foreground">
                                {correction.assignment.assignment_no}
                                {correction.assignment.employee
                                    ? ` · ${correction.assignment.employee.name}`
                                    : ''}
                            </span>
                        ) : null}
                    </span>
                }
                backHref={correctionsIndex.url()}
                backLabel="Back to Corrections"
                actions={
                    <div className="flex flex-wrap items-center gap-2">
                        {correction.can_approve ? (
                            <Button onClick={() => openDecision('approve')}>
                                Approve
                            </Button>
                        ) : null}
                        {correction.can_reject ? (
                            <Button
                                variant="destructive"
                                onClick={() => openDecision('reject')}
                            >
                                Reject
                            </Button>
                        ) : null}
                        {correction.can_cancel ? (
                            <Button
                                variant="outline"
                                onClick={() => openDecision('cancel')}
                            >
                                Cancel
                            </Button>
                        ) : null}
                    </div>
                }
            />

            {correction.has_conflict ? (
                <div className="mb-6 rounded-xl border border-amber-500/40 bg-amber-500/10 p-4">
                    <div className="flex items-center gap-2 text-sm font-semibold text-amber-700 dark:text-amber-300">
                        <AlertTriangle className="size-4" aria-hidden />
                        Values have changed since this correction was requested
                    </div>
                    <p className="mt-1 text-xs text-amber-800/80 dark:text-amber-200/80">
                        Review the live values below — the assignment record no
                        longer matches the original snapshot.
                    </p>
                </div>
            ) : null}

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
                <div className="space-y-6">
                    <Card className="border-border/80 dark:border-white/10">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">
                                Proposed Changes
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <CorrectionValuesTable
                                fields={fields}
                                original={correction.original_values}
                                proposed={correction.proposed_values}
                                live={correction.live_values}
                            />
                        </CardContent>
                    </Card>

                    {correction.applied_values ? (
                        <Card className="border-border/80 dark:border-white/10">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    Applied Values
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <CorrectionValuesTable
                                    fields={Object.keys(
                                        correction.applied_values,
                                    )}
                                    original={correction.original_values}
                                    proposed={correction.applied_values}
                                    live={correction.live_values}
                                    proposedLabel="Applied"
                                />
                            </CardContent>
                        </Card>
                    ) : null}

                    <Card className="border-border/80 dark:border-white/10">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Reason</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                                {correction.reason || '—'}
                            </p>
                        </CardContent>
                    </Card>

                    {correction.decision_notes ? (
                        <Card className="border-border/80 dark:border-white/10">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    Decision Notes
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                                    {correction.decision_notes}
                                </p>
                            </CardContent>
                        </Card>
                    ) : null}
                </div>

                <div className="space-y-6">
                    {correction.status === 'pending' ? (
                        <Card className="border-border/80 dark:border-white/10">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    Approval SLA
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 pt-0">
                                <div className="flex items-center justify-between gap-3 text-sm">
                                    <span className="text-muted-foreground">
                                        Status
                                    </span>
                                    <span className="font-medium">
                                        Pending Approval
                                    </span>
                                </div>
                                <div className="flex items-center justify-between gap-3 text-sm">
                                    <span className="text-muted-foreground">
                                        Requested
                                    </span>
                                    <span>
                                        {formatDisplayDate(
                                            correction.requested_at,
                                        )}
                                    </span>
                                </div>
                                <div className="flex items-center justify-between gap-3 text-sm">
                                    <span className="text-muted-foreground">
                                        Age
                                    </span>
                                    <span>{correction.age_label}</span>
                                </div>
                                <div className="flex items-center justify-between gap-3 text-sm">
                                    <span className="text-muted-foreground">
                                        SLA
                                    </span>
                                    <CrewMovementCorrectionSlaBadge
                                        status={correction.sla_status}
                                        label={correction.sla_label}
                                    />
                                </div>
                                {correction.is_overdue &&
                                correction.days_beyond_sla > 0 ? (
                                    <p className="border-t border-border/70 pt-3 text-xs text-destructive">
                                        {correction.days_beyond_sla}{' '}
                                        {correction.days_beyond_sla === 1
                                            ? 'day'
                                            : 'days'}{' '}
                                        beyond the 4-day SLA
                                    </p>
                                ) : null}
                            </CardContent>
                        </Card>
                    ) : null}

                    <Card className="border-border/80 dark:border-white/10">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Details</CardTitle>
                        </CardHeader>
                        <CardContent className="pt-0">
                            <CrewMetadataField
                                label="Assignment"
                                value={
                                    correction.assignment ? (
                                        <a
                                            className="text-primary hover:underline"
                                            href={showAssignment.url(
                                                correction.assignment.id,
                                            )}
                                        >
                                            {
                                                correction.assignment
                                                    .assignment_no
                                            }
                                        </a>
                                    ) : (
                                        '—'
                                    )
                                }
                            />
                            <CrewMetadataField
                                label="Employee"
                                value={
                                    correction.assignment?.employee?.name ?? '—'
                                }
                            />
                            <CrewMetadataField
                                label="Vessel"
                                value={
                                    correction.assignment?.vessel?.name ?? '—'
                                }
                            />
                            <CrewMetadataField
                                label="Phase"
                                value={
                                    correction.phase
                                        ? `${correction.phase.phase_code.toUpperCase()} · ${correction.phase.phase_label}`
                                        : '—'
                                }
                            />
                            <CrewMetadataField
                                label="Requested by"
                                value={correction.requester?.name ?? '—'}
                            />
                            <CrewMetadataField
                                label="Requested at"
                                value={formatDisplayDateTime12h(
                                    correction.requested_at,
                                )}
                            />
                            <CrewMetadataField
                                label="Decision by"
                                value={correction.decision_maker?.name ?? '—'}
                            />
                            <CrewMetadataField
                                label="Decided at"
                                value={formatDisplayDateTime12h(
                                    correction.decided_at,
                                )}
                            />
                        </CardContent>
                    </Card>
                </div>
            </div>

            <CrewMovementCorrectionDecisionDialog
                mode={decisionMode}
                open={dialogOpen}
                onOpenChange={setDialogOpen}
                correctionId={correction.id}
            />
        </Main>
    );
}
