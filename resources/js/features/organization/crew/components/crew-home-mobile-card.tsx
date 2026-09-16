import { Link, router } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { CrewEmployeeIdentity } from '@/features/organization/crew/components/crew-employee-identity';
import type { CurrentCrewHomeRow } from '@/features/organization/crew/types';
import { formatDisplayDateTime } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import {
    create as createAssignment,
    show as showAssignment,
} from '@/routes/organization/crew-assignments';

function availabilityBadgeClass(
    status: CurrentCrewHomeRow['availability_status'],
) {
    switch (status) {
        case 'over_limit':
            return 'border-destructive/30 bg-destructive/10 text-destructive';
        case 'near_limit':
            return 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400';
        default:
            return 'border-border bg-muted/40 text-muted-foreground';
    }
}

export function CrewHomeMobileCard({ row }: { row: CurrentCrewHomeRow }) {
    const assignmentHref =
        row.latest_assignment && row.can.view_assignment
            ? showAssignment.url(row.latest_assignment.id)
            : null;

    return (
        <Card className="glass-card">
            <CardContent className="space-y-3 p-4">
                <CrewEmployeeIdentity employee={row.employee} />

                <div className="grid grid-cols-2 gap-2 text-xs">
                    <div>
                        <p className="text-muted-foreground">Rank</p>
                        <p className="font-medium">{row.rank?.name ?? '—'}</p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">Last vessel</p>
                        <p className="font-medium">
                            {row.last_vessel?.name ?? '—'}
                        </p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">Home since</p>
                        <p className="font-medium">
                            {row.home_since
                                ? formatDisplayDateTime(row.home_since)
                                : '—'}
                        </p>
                    </div>
                    <div>
                        <p className="text-muted-foreground">Days at home</p>
                        <p className="font-medium tabular-nums">
                            {row.days_at_home ?? '—'}
                        </p>
                    </div>
                </div>

                <div className="space-y-1">
                    <Badge
                        variant="outline"
                        className={cn(
                            availabilityBadgeClass(row.availability_status),
                        )}
                    >
                        {row.availability_label}
                    </Badge>
                    {row.availability_detail ? (
                        <p className="text-[11px] text-muted-foreground">
                            {row.availability_detail}
                        </p>
                    ) : null}
                </div>

                {row.latest_assignment ? (
                    <p className="text-xs text-muted-foreground">
                        Latest assignment:{' '}
                        {assignmentHref ? (
                            <Link
                                href={assignmentHref}
                                className="font-medium text-primary hover:underline"
                            >
                                {row.latest_assignment.assignment_no}
                            </Link>
                        ) : (
                            <span className="font-medium text-foreground">
                                {row.latest_assignment.assignment_no}
                            </span>
                        )}
                    </p>
                ) : null}

                <div className="flex flex-wrap gap-2">
                    {assignmentHref ? (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => router.visit(assignmentHref)}
                        >
                            View assignment
                        </Button>
                    ) : null}
                    {row.can.start_assignment ? (
                        <Button
                            type="button"
                            size="sm"
                            onClick={() =>
                                router.visit(
                                    createAssignment.url({
                                        query: {
                                            employee_id: row.employee.id,
                                        },
                                    }),
                                )
                            }
                        >
                            Start Assignment
                        </Button>
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );
}
