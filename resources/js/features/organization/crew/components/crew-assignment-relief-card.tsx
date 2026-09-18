import { Link } from '@inertiajs/react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CrewMetadataField } from '@/features/organization/crew/components/crew-metadata-field';
import { CrewPhaseBadge } from '@/features/organization/crew/components/crew-phase-badge';
import { CrewReliefReadinessBadge } from '@/features/organization/crew/components/crew-relief-readiness-badge';
import type { CrewAssignmentDetail } from '@/features/organization/crew/types';
import { formatDisplayDate } from '@/lib/format-date';
import { show as showAssignment } from '@/routes/organization/crew-assignments';

export function CrewAssignmentReliefCard({
    assignment,
    reliefHref,
    reliefActionLabel,
}: {
    assignment: CrewAssignmentDetail;
    reliefHref: string | null;
    reliefActionLabel: string;
}): ReactElement {
    return (
        <Card className="border-border/80 dark:border-white/10">
            <CardHeader className="flex flex-row items-center justify-between gap-3 border-b border-border/50 pb-3 dark:border-white/5">
                <CardTitle className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                    Relief Readiness
                </CardTitle>
                {reliefHref ? (
                    <Button
                        asChild
                        variant="outline"
                        size="sm"
                        className="h-7 rounded-lg text-xs"
                    >
                        <Link href={reliefHref}>{reliefActionLabel}</Link>
                    </Button>
                ) : null}
            </CardHeader>
            <CardContent className="space-y-3 pt-4">
                <CrewReliefReadinessBadge
                    relief_status={assignment.relief_status}
                    relief_status_label={assignment.relief_status_label}
                    relief_risk={assignment.relief_risk}
                    relief_risk_label={assignment.relief_risk_label}
                    relief_employee={assignment.relief_employee}
                />
                <div className="space-y-0 divide-y divide-border/40 text-xs">
                    <CrewMetadataField
                        label="Planned join"
                        value={formatDisplayDate(
                            assignment.relief_planned_join_date,
                        )}
                    />
                    <CrewMetadataField
                        label="Days until sign-off"
                        value={
                            assignment.days_until_signoff !== null
                                ? String(assignment.days_until_signoff)
                                : '—'
                        }
                    />
                    {assignment.relief_phase_code ? (
                        <CrewMetadataField
                            label="Relief phase"
                            value={
                                <CrewPhaseBadge
                                    code={assignment.relief_phase_code}
                                    label={
                                        assignment.relief_phase_label ??
                                        assignment.relief_phase_code
                                    }
                                    status={
                                        assignment.relief_phase_status ??
                                        undefined
                                    }
                                />
                            }
                        />
                    ) : null}
                    {assignment.relief_crew_assignment_id ? (
                        <CrewMetadataField
                            label="Relief assignment"
                            value={
                                <Link
                                    href={showAssignment.url(
                                        assignment.relief_crew_assignment_id,
                                    )}
                                    className="font-mono text-primary hover:underline"
                                >
                                    Open assignment
                                </Link>
                            }
                        />
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );
}
