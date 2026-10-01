import { Link } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import RequirementController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementController';
import {
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { TableCell, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { RequirementIndexRow } from '@/types/recruitment';
import { RequirementIndexQuickAction } from '../lib/requirement-index-quick-action';
import { RequirementActionMenu } from './requirement-action-menu';
import type { RequirementActionHandlers } from './requirement-action-menu';
import {
    RequirementDeadlineBadge,
    RequirementPriorityBadge,
    RequirementStatusBadge,
} from './requirement-status-badge';

type Props = RequirementActionHandlers & {
    row: RequirementIndexRow;
};

function getInitials(name: string): string {
    const parts = name.trim().split(/\s+/);

    if (parts.length >= 2) {
        return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
    }

    return parts[0].slice(0, 2).toUpperCase();
}

export function RequirementTableRow({
    row,
    onEdit,
    onSubmit,
    onApprove,
    onReturn,
    onResubmit,
    onHold,
    onResume,
    onExtend,
    onChangeHeadcount,
    onFill,
    onCancel,
    onReopen,
    onRepeat,
}: Props) {
    const showUrl = RequirementController.show.url(row.id);
    const handlers = {
        onEdit,
        onSubmit,
        onApprove,
        onReturn,
        onResubmit,
        onHold,
        onResume,
        onExtend,
        onChangeHeadcount,
        onFill,
        onCancel,
        onReopen,
        onRepeat,
    };

    return (
        <TableRow
            className={cn(
                dataTableBodyRowClass(),
                'group',
                row.deadline_health === 'overdue' && 'bg-rose-500/[0.025]',
            )}
        >
            <TableCell className={cn(dataTableCellPrimaryClass(), 'py-4')}>
                <div className="flex max-w-[280px] flex-col gap-1.5">
                    <div className="flex flex-wrap items-center gap-2">
                        <Link
                            href={showUrl}
                            className="font-mono text-xs font-semibold text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            {row.requirement_number}
                        </Link>
                        <RequirementPriorityBadge priority={row.priority} />
                    </div>
                    <Link
                        href={showUrl}
                        className="truncate text-sm font-semibold hover:underline"
                        title={row.client_name}
                    >
                        {row.client_name}
                    </Link>
                    {(row.project_title || row.location) && (
                        <span
                            className="truncate text-xs font-normal text-muted-foreground"
                            title={[row.project_title, row.location]
                                .filter(Boolean)
                                .join(' · ')}
                        >
                            {[row.project_title, row.location]
                                .filter(Boolean)
                                .join(' · ')}
                        </span>
                    )}
                    {row.repeated_from_number && (
                        <span className="flex items-center gap-1 text-xs font-normal text-muted-foreground">
                            <Copy className="size-3" aria-hidden="true" />
                            From {row.repeated_from_number}
                        </span>
                    )}
                </div>
            </TableCell>
            <TableCell className={dataTableCellClass()}>
                <div className="flex max-w-[260px] flex-col gap-2">
                    <div className="flex items-baseline gap-1.5">
                        <span className="text-lg font-semibold tabular-nums">
                            {row.total_headcount}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {row.total_headcount === 1 ? 'person' : 'people'} ·{' '}
                            {row.positions_count}{' '}
                            {row.positions_count === 1 ? 'role' : 'roles'}
                        </span>
                    </div>
                    <div className="flex flex-wrap gap-1.5">
                        {row.positions_summary.slice(0, 2).map((position) => (
                            <span
                                key={position.id}
                                className="inline-flex max-w-full items-center gap-1.5 rounded-md bg-muted px-2 py-1 text-xs"
                                title={`${position.position_title}: ${position.required_headcount} requested`}
                            >
                                <span className="truncate">
                                    {position.position_title}
                                </span>
                                <span className="shrink-0 font-medium tabular-nums">
                                    ×{position.required_headcount}
                                </span>
                            </span>
                        ))}
                        {row.positions_summary.length > 2 && (
                            <Link
                                href={showUrl}
                                className="rounded-md px-1 py-1 text-xs text-primary hover:underline"
                            >
                                +{row.positions_summary.length - 2} more
                            </Link>
                        )}
                    </div>
                </div>
            </TableCell>

            <TableCell
                className={cn(dataTableCellClass(), 'whitespace-nowrap')}
            >
                <div className="flex flex-col gap-1">
                    <span className="text-xs font-semibold text-foreground">
                        {row.required_by_date_formatted || 'No deadline'}
                    </span>
                    <RequirementDeadlineBadge
                        health={row.deadline_health}
                        label={row.days_label}
                    />
                </div>
            </TableCell>

            <TableCell
                className={cn(dataTableCellClass(), 'whitespace-nowrap')}
            >
                {row.assigned_recruiter_name ? (
                    <div className="flex items-center gap-1.5">
                        <div
                            className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary"
                            aria-hidden="true"
                        >
                            {getInitials(row.assigned_recruiter_name)}
                        </div>
                        <span
                            className="max-w-[110px] truncate text-xs font-medium text-foreground"
                            title={row.assigned_recruiter_name}
                        >
                            {row.assigned_recruiter_name}
                        </span>
                    </div>
                ) : (
                    <span className="text-xs text-muted-foreground">
                        Unassigned
                    </span>
                )}
            </TableCell>

            <TableCell
                className={cn(dataTableCellClass(), 'whitespace-nowrap')}
            >
                <RequirementStatusBadge
                    status={row.status}
                    label={row.status_label}
                />
            </TableCell>

            <TableCell
                className={cn(
                    dataTableCellClass(),
                    'text-right whitespace-nowrap',
                )}
            >
                <div
                    className="flex items-center justify-end gap-1.5"
                    onClick={(e) => e.stopPropagation()}
                >
                    <RequirementIndexQuickAction
                        row={row}
                        handlers={{
                            onSubmit,
                            onApprove,
                            onResubmit,
                            onResume,
                            onFill,
                            onExtend,
                            onRepeat,
                        }}
                    />

                    <RequirementActionMenu row={row} {...handlers} />
                </div>
            </TableCell>
        </TableRow>
    );
}
