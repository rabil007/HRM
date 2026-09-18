import { Link } from '@inertiajs/react';
import { ChevronRight, GitFork, Layers, UserCheck } from 'lucide-react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CrewMetadataField } from '@/features/organization/crew/components/crew-metadata-field';
import type { CrewAssignmentDetail } from '@/features/organization/crew/types';
import { formatDisplayDate } from '@/lib/format-date';
import { show as showAssignment } from '@/routes/organization/crew-assignments';
import { index as crewPlanningIndex } from '@/routes/organization/crew-planning';

export function CrewAssignmentRelationships({
    assignment,
    canViewPlanning = false,
}: {
    assignment: CrewAssignmentDetail;
    canViewPlanning?: boolean;
}): ReactElement | null {
    const hasLinked =
        Boolean(assignment.previous_assignment) ||
        (assignment.next_assignments?.length ?? 0) > 0;
    const hasRelieves = Boolean(assignment.relieves);
    const hasPlanning = Boolean(assignment.planning_assignment_id);

    if (!hasLinked && !hasRelieves && !hasPlanning) {
        return null;
    }

    return (
        <Card className="border-border/80 dark:border-white/10">
            <CardHeader className="border-b border-border/50 pb-3 dark:border-white/5">
                <div className="flex items-center gap-2">
                    <GitFork className="size-3.5 text-muted-foreground" />
                    <CardTitle className="text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                        Assignment Relationships
                    </CardTitle>
                </div>
            </CardHeader>
            <CardContent className="space-y-6 pt-4">
                {/* Linked Assignments (Previous / Next) */}
                {hasLinked ? (
                    <div className="space-y-2">
                        <p className="text-[10px] font-bold tracking-wide text-muted-foreground/60 uppercase">
                            Linked Assignments
                        </p>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {assignment.previous_assignment ? (
                                <Link
                                    href={showAssignment.url(
                                        assignment.previous_assignment.id,
                                    )}
                                    className="group flex items-center justify-between gap-3 rounded-lg border border-border/50 bg-muted/30 px-3 py-2.5 transition-colors hover:border-border hover:bg-muted/60"
                                >
                                    <div className="min-w-0">
                                        <p className="text-[10px] font-bold tracking-wide text-muted-foreground/60 uppercase">
                                            ← Previous
                                        </p>
                                        <p className="mt-0.5 truncate font-mono text-sm font-medium text-foreground">
                                            {
                                                assignment.previous_assignment
                                                    .assignment_no
                                            }
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {
                                                assignment.previous_assignment
                                                    .status_label
                                            }
                                            {assignment.previous_assignment
                                                .vessel_name
                                                ? ` · ${assignment.previous_assignment.vessel_name}`
                                                : ''}
                                        </p>
                                    </div>
                                    <ChevronRight className="size-4 shrink-0 text-muted-foreground/40 transition-transform group-hover:translate-x-0.5" />
                                </Link>
                            ) : null}

                            {(assignment.next_assignments ?? []).map((next) => (
                                <Link
                                    key={next.id}
                                    href={showAssignment.url(next.id)}
                                    className="group flex items-center justify-between gap-3 rounded-lg border border-primary/20 bg-primary/3 px-3 py-2.5 transition-colors hover:border-primary/40 hover:bg-primary/8"
                                >
                                    <div className="min-w-0">
                                        <p className="text-[10px] font-bold tracking-wide text-primary/60 uppercase">
                                            Next →
                                        </p>
                                        <p className="mt-0.5 truncate font-mono text-sm font-medium text-foreground">
                                            {next.assignment_no}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {next.status_label}
                                            {next.vessel_name
                                                ? ` · ${next.vessel_name}`
                                                : ''}
                                        </p>
                                    </div>
                                    <ChevronRight className="size-4 shrink-0 text-primary/40 transition-transform group-hover:translate-x-0.5" />
                                </Link>
                            ))}
                        </div>
                    </div>
                ) : null}

                {/* Relieves Section */}
                {hasRelieves && assignment.relieves ? (
                    <div className="space-y-2 rounded-lg border border-border/50 bg-card p-3">
                        <div className="flex items-center gap-1.5 text-xs font-semibold text-foreground">
                            <UserCheck className="size-3.5 text-muted-foreground" />
                            <span>Relieves</span>
                        </div>
                        <div className="space-y-0 divide-y divide-border/40 pt-1 text-xs">
                            <CrewMetadataField
                                label="Source assignment"
                                value={
                                    <Link
                                        href={showAssignment.url(
                                            assignment.relieves
                                                .source_assignment_id,
                                        )}
                                        className="font-mono text-primary hover:underline"
                                    >
                                        {
                                            assignment.relieves
                                                .source_assignment_no
                                        }
                                    </Link>
                                }
                            />
                            <CrewMetadataField
                                label="Employee"
                                value={
                                    assignment.relieves.source_employee
                                        ? assignment.relieves.source_employee
                                              .name
                                        : '—'
                                }
                            />
                            <CrewMetadataField
                                label="Vessel"
                                value={
                                    assignment.relieves.source_vessel?.name ??
                                    '—'
                                }
                            />
                            <CrewMetadataField
                                label="Rank"
                                value={
                                    assignment.relieves.source_rank?.name ?? '—'
                                }
                            />
                            <CrewMetadataField
                                label="Planned sign-off"
                                value={formatDisplayDate(
                                    assignment.relieves
                                        .source_planned_signoff_at,
                                )}
                            />
                        </div>
                        {canViewPlanning ? (
                            <div className="pt-2">
                                <Button
                                    asChild
                                    variant="link"
                                    className="h-auto p-0 text-xs"
                                >
                                    <Link
                                        href={crewPlanningIndex.url({
                                            query: {
                                                vessel_id:
                                                    assignment.vessel?.id ??
                                                    undefined,
                                                rank_id:
                                                    assignment.rank?.id ??
                                                    undefined,
                                                search:
                                                    assignment.employee?.name ??
                                                    undefined,
                                            },
                                        })}
                                    >
                                        Open Crew Planning
                                    </Link>
                                </Button>
                            </div>
                        ) : null}
                    </div>
                ) : null}

                {/* Planning Link Section */}
                {hasPlanning ? (
                    <div className="flex items-center justify-between gap-3 rounded-lg border border-border/50 bg-muted/20 px-3 py-2.5">
                        <div className="flex items-center gap-2">
                            <Layers className="size-4 text-muted-foreground/70" />
                            <p className="text-xs text-muted-foreground">
                                Created from crew planning assignment #
                                <span className="font-mono font-medium text-foreground">
                                    {assignment.planning_assignment_id}
                                </span>
                                .
                            </p>
                        </div>
                        {canViewPlanning ? (
                            <Button
                                asChild
                                variant="link"
                                className="h-auto p-0 text-xs font-medium"
                            >
                                <Link href={crewPlanningIndex.url()}>
                                    Open Crew Planning
                                </Link>
                            </Button>
                        ) : null}
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}
