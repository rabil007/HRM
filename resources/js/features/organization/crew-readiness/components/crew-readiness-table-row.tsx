import { Link, router } from '@inertiajs/react';
import { ChevronRight, ExternalLink } from 'lucide-react';
import type React from 'react';
import {
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { TableCell, TableRow } from '@/components/ui/table';
import { CrewMobilisationReadinessBadge } from '@/features/organization/crew/components/crew-mobilisation-readiness-badge';
import { formatJoinTimeline } from '@/features/organization/crew-readiness/lib/crew-readiness-query';
import type { CrewReadinessRow } from '@/features/organization/crew-readiness/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

export function CrewReadinessTableRow({
    row,
    onSelect,
}: {
    row: CrewReadinessRow;
    onSelect: (row: CrewReadinessRow) => void;
}) {
    const timeline = formatJoinTimeline(row.days_until_join, row.is_overdue);

    return (
        <TableRow
            className={cn(dataTableBodyRowClass(true), 'cursor-pointer')}
            onClick={() => onSelect(row)}
        >
            {/* Crew */}
            <TableCell
                className={cn(dataTableCellPrimaryClass(), 'min-w-[170px]')}
            >
                <div className="min-w-0">
                    {row.employee.href ? (
                        <Link
                            href={row.employee.href}
                            className="truncate font-semibold text-foreground hover:underline"
                            onClick={(e: React.MouseEvent) =>
                                e.stopPropagation()
                            }
                        >
                            {row.employee.name}
                        </Link>
                    ) : (
                        <p className="truncate font-semibold text-foreground">
                            {row.employee.name}
                        </p>
                    )}
                    <p className="truncate text-[11px] text-muted-foreground">
                        {row.employee.employee_no ?? '—'}
                    </p>
                </div>
            </TableCell>

            {/* Rank */}
            <TableCell className={cn(dataTableCellClass(), 'min-w-[130px]')}>
                <p className="truncate font-medium text-foreground">
                    {row.position?.title ?? '—'}
                </p>
            </TableCell>

            {/* Vessel */}
            <TableCell className={cn(dataTableCellClass(), 'min-w-[140px]')}>
                {row.vessel?.href ? (
                    <Link
                        href={row.vessel.href}
                        className="truncate font-medium text-foreground hover:underline"
                        onClick={(e: React.MouseEvent) => e.stopPropagation()}
                    >
                        {row.vessel.name}
                    </Link>
                ) : (
                    <p className="truncate text-foreground">
                        {row.vessel?.name ?? '—'}
                    </p>
                )}
            </TableCell>

            {/* Source / Stage */}
            <TableCell className={cn(dataTableCellClass(), 'min-w-[140px]')}>
                <div className="flex flex-col gap-0.5">
                    <Badge
                        variant="secondary"
                        className="h-4.5 w-fit px-1.5 py-0 text-[10px] font-medium"
                    >
                        {row.source_label}
                    </Badge>
                    <span className="truncate text-[11px] text-muted-foreground">
                        {row.stage_label}
                    </span>
                </div>
            </TableCell>

            {/* Expected Arrival */}
            <TableCell className={cn(dataTableCellClass(), 'min-w-[105px]')}>
                <span className="text-xs text-muted-foreground">
                    {formatDisplayDate(row.expected_arrival_date)}
                </span>
            </TableCell>

            {/* Expected Join */}
            <TableCell className={cn(dataTableCellClass(), 'min-w-[125px]')}>
                <div className="flex flex-col gap-0.5">
                    <span className="text-xs font-semibold text-foreground">
                        {formatDisplayDate(row.expected_join_date)}
                    </span>
                    {timeline ? (
                        <span className={cn('text-[10px]', timeline.tone)}>
                            {timeline.label}
                        </span>
                    ) : null}
                </div>
            </TableCell>

            {/* Expected Sign-Off */}
            <TableCell className={cn(dataTableCellClass(), 'min-w-[105px]')}>
                <span className="text-xs text-muted-foreground">
                    {formatDisplayDate(row.expected_signoff_date)}
                </span>
            </TableCell>

            {/* Readiness */}
            <TableCell className={cn(dataTableCellClass(), 'min-w-[130px]')}>
                <CrewMobilisationReadinessBadge readiness={row.readiness} />
            </TableCell>

            {/* Outstanding Items */}
            <TableCell
                className={cn(
                    dataTableCellClass(),
                    'max-w-[240px] min-w-[160px]',
                )}
            >
                {row.readiness.problems.length > 0 ? (
                    <div className="flex flex-col gap-1">
                        {row.readiness.problems.slice(0, 2).map((prob, idx) => (
                            <span
                                key={idx}
                                className={cn(
                                    'truncate text-[11px] leading-tight font-medium',
                                    prob.severity === 'critical'
                                        ? 'text-red-700 dark:text-red-400'
                                        : 'text-amber-700 dark:text-amber-400',
                                )}
                                title={prob.message}
                            >
                                {prob.label}
                            </span>
                        ))}
                        {row.readiness.problems.length > 2 ? (
                            <span className="text-[10px] text-muted-foreground">
                                +{row.readiness.problems.length - 2} more
                            </span>
                        ) : null}
                    </div>
                ) : row.readiness.has_configured_checks ? (
                    <span className="text-[11px] font-medium text-emerald-600 dark:text-emerald-400">
                        All checks clear
                    </span>
                ) : (
                    <span className="text-[11px] text-muted-foreground">—</span>
                )}
            </TableCell>

            {/* Action */}
            <TableCell
                className={cn(dataTableActionsCellClass(), 'min-w-[110px]')}
            >
                <div
                    className="flex items-center justify-end gap-1.5"
                    onClick={(e) => e.stopPropagation()}
                >
                    {row.source_type === 'planning' &&
                    row.can_view_plan &&
                    row.plan_href ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-7 gap-1 px-2 text-xs"
                            onClick={() => router.visit(row.plan_href!)}
                            title="Open Crew Plan"
                        >
                            <span>Plan</span>
                            <ExternalLink className="h-3 w-3 text-muted-foreground" />
                        </Button>
                    ) : row.source_type === 'assignment' &&
                      row.can_view_assignment &&
                      row.assignment_href ? (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-7 gap-1 px-2 text-xs"
                            onClick={() => router.visit(row.assignment_href!)}
                            title="Open Assignment"
                        >
                            <span>Assignment</span>
                            <ExternalLink className="h-3 w-3 text-muted-foreground" />
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            className="h-7 gap-1 px-2 text-xs text-muted-foreground"
                            onClick={() => onSelect(row)}
                        >
                            <span>Details</span>
                            <ChevronRight className="h-3.5 w-3.5" />
                        </Button>
                    )}
                </div>
            </TableCell>
        </TableRow>
    );
}
