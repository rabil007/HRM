import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Ban,
    CheckCircle2,
    Clock,
    Copy,
    Edit3,
    Eye,
    Flame,
    MoreHorizontal,
    PauseCircle,
    PlayCircle,
    RotateCcw,
    Users,
} from 'lucide-react';
import RequirementController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementController';
import {
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { TableCell, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { RequirementIndexRow } from '@/types/recruitment';

type Props = {
    row: RequirementIndexRow;
    onEdit: (row: RequirementIndexRow) => void;
    onOpen: (row: RequirementIndexRow) => void;
    onHold: (row: RequirementIndexRow) => void;
    onResume: (row: RequirementIndexRow) => void;
    onExtend: (row: RequirementIndexRow) => void;
    onChangeHeadcount: (row: RequirementIndexRow) => void;
    onFill: (row: RequirementIndexRow) => void;
    onCancel: (row: RequirementIndexRow) => void;
    onReopen: (row: RequirementIndexRow) => void;
    onRepeat: (row: RequirementIndexRow) => void;
};

/** Two-letter initials helper — first initial + last initial */
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
    onOpen,
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
                        {row.priority === 'urgent' && (
                            <span className="inline-flex items-center gap-1 rounded-md bg-rose-500/10 px-1.5 py-0.5 text-xs font-medium text-rose-700 dark:text-rose-400">
                                <Flame className="size-3" aria-hidden="true" />
                                Urgent
                            </span>
                        )}
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

            {/* Required By & Deadline Health */}
            <TableCell
                className={cn(dataTableCellClass(), 'whitespace-nowrap')}
            >
                <div className="flex flex-col gap-1">
                    <span className="text-xs font-semibold text-foreground">
                        {row.required_by_date_formatted || 'No deadline'}
                    </span>
                    {row.deadline_health && (
                        <Badge
                            variant="outline"
                            className={cn(
                                'w-fit gap-1 px-1.5 py-0 text-xs font-medium',
                                row.deadline_health === 'overdue' &&
                                    'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-400',
                                row.deadline_health === 'due_soon' &&
                                    'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400',
                                row.deadline_health === 'on_track' &&
                                    'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
                            )}
                        >
                            {row.deadline_health === 'overdue' && (
                                <AlertTriangle
                                    className="h-2.5 w-2.5"
                                    aria-hidden="true"
                                />
                            )}
                            {row.deadline_health === 'due_soon' && (
                                <Clock
                                    className="h-2.5 w-2.5"
                                    aria-hidden="true"
                                />
                            )}
                            {row.days_label}
                        </Badge>
                    )}
                </div>
            </TableCell>

            {/* Owner (Recruiter) */}
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

            {/* Status */}
            <TableCell
                className={cn(dataTableCellClass(), 'whitespace-nowrap')}
            >
                <Badge
                    variant="outline"
                    className={cn(
                        'px-2 py-0.5 text-xs font-semibold',
                        row.status === 'open' &&
                            'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
                        row.status === 'draft' &&
                            'border-zinc-500/30 bg-zinc-500/10 text-zinc-600 dark:text-zinc-400',
                        row.status === 'on_hold' &&
                            'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400',
                        row.status === 'completed' &&
                            'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-400',
                        row.status === 'cancelled' &&
                            'border-rose-500/30 bg-rose-500/10 text-rose-700 dark:text-rose-400',
                    )}
                >
                    {row.status_label}
                </Badge>
            </TableCell>

            {/* Actions: Contextual Primary + Overflow */}
            <TableCell
                className={cn(
                    dataTableCellClass(),
                    'text-right whitespace-nowrap',
                )}
            >
                {/* Prevent row-click accidentally triggering on action area */}
                <div
                    className="flex items-center justify-end gap-1.5"
                    onClick={(e) => e.stopPropagation()}
                >
                    {/* Contextual primary button */}
                    {row.next_action === 'open' && row.can_open && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => onOpen(row)}
                            className="h-8 gap-1 border-emerald-500/40 text-xs text-emerald-700 hover:bg-emerald-500/10 dark:text-emerald-400"
                        >
                            <PlayCircle
                                className="h-3.5 w-3.5"
                                aria-hidden="true"
                            />
                            Open
                        </Button>
                    )}
                    {row.next_action === 'resume' && row.can_resume && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => onResume(row)}
                            className="h-8 gap-1 border-emerald-500/40 text-xs text-emerald-700 hover:bg-emerald-500/10 dark:text-emerald-400"
                        >
                            <PlayCircle
                                className="h-3.5 w-3.5"
                                aria-hidden="true"
                            />
                            Resume
                        </Button>
                    )}
                    {row.next_action === 'fill' && row.can_fill && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => onFill(row)}
                            className="h-8 gap-1 border-sky-500/40 text-xs text-sky-700 hover:bg-sky-500/10 dark:text-sky-400"
                        >
                            <CheckCircle2
                                className="h-3.5 w-3.5"
                                aria-hidden="true"
                            />
                            Mark Filled
                        </Button>
                    )}
                    {row.next_action === 'extend' && row.can_extend && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => onExtend(row)}
                            className="h-8 gap-1 border-amber-500/40 text-xs text-amber-700 hover:bg-amber-500/10 dark:text-amber-400"
                        >
                            <Clock className="h-3.5 w-3.5" aria-hidden="true" />
                            Extend
                        </Button>
                    )}
                    {row.next_action === 'repeat' && row.can_repeat && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => onRepeat(row)}
                            className="h-8 gap-1 border-primary/40 text-xs text-primary hover:bg-primary/10"
                        >
                            <Copy className="h-3.5 w-3.5" aria-hidden="true" />
                            Repeat
                        </Button>
                    )}

                    {/* Overflow Dropdown */}
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="sm"
                                className="h-8 w-8 p-0 text-muted-foreground hover:text-foreground"
                                aria-label={`More actions for ${row.requirement_number}`}
                            >
                                <MoreHorizontal
                                    className="h-4 w-4"
                                    aria-hidden="true"
                                />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-48">
                            <DropdownMenuItem asChild>
                                <Link
                                    href={showUrl}
                                    className="cursor-pointer gap-2"
                                >
                                    <Eye
                                        className="h-4 w-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <span>View Details</span>
                                </Link>
                            </DropdownMenuItem>

                            {row.can_edit && (
                                <DropdownMenuItem
                                    onClick={() => onEdit(row)}
                                    className="cursor-pointer gap-2"
                                >
                                    <Edit3
                                        className="h-4 w-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <span>Edit Requirement</span>
                                </DropdownMenuItem>
                            )}

                            {row.can_change_headcount && (
                                <DropdownMenuItem
                                    onClick={() => onChangeHeadcount(row)}
                                    className="cursor-pointer gap-2"
                                >
                                    <Users
                                        className="h-4 w-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <span>Revise Headcount</span>
                                </DropdownMenuItem>
                            )}

                            {row.can_extend && (
                                <DropdownMenuItem
                                    onClick={() => onExtend(row)}
                                    className="cursor-pointer gap-2"
                                >
                                    <Clock
                                        className="h-4 w-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <span>Extend Deadline</span>
                                </DropdownMenuItem>
                            )}

                            {row.can_hold && (
                                <DropdownMenuItem
                                    onClick={() => onHold(row)}
                                    className="cursor-pointer gap-2"
                                >
                                    <PauseCircle
                                        className="h-4 w-4 text-amber-700 dark:text-amber-400"
                                        aria-hidden="true"
                                    />
                                    <span>Put On Hold</span>
                                </DropdownMenuItem>
                            )}

                            {row.can_resume && (
                                <DropdownMenuItem
                                    onClick={() => onResume(row)}
                                    className="cursor-pointer gap-2"
                                >
                                    <PlayCircle
                                        className="h-4 w-4 text-emerald-700 dark:text-emerald-400"
                                        aria-hidden="true"
                                    />
                                    <span>Resume Requirement</span>
                                </DropdownMenuItem>
                            )}

                            {row.can_fill && (
                                <DropdownMenuItem
                                    onClick={() => onFill(row)}
                                    className="cursor-pointer gap-2"
                                >
                                    <CheckCircle2
                                        className="h-4 w-4 text-sky-700 dark:text-sky-400"
                                        aria-hidden="true"
                                    />
                                    <span>Mark as Filled</span>
                                </DropdownMenuItem>
                            )}

                            {row.can_reopen && (
                                <DropdownMenuItem
                                    onClick={() => onReopen(row)}
                                    className="cursor-pointer gap-2"
                                >
                                    <RotateCcw
                                        className="h-4 w-4 text-primary"
                                        aria-hidden="true"
                                    />
                                    <span>Reopen Requirement</span>
                                </DropdownMenuItem>
                            )}

                            {row.can_repeat && (
                                <DropdownMenuItem
                                    onClick={() => onRepeat(row)}
                                    className="cursor-pointer gap-2"
                                >
                                    <Copy
                                        className="h-4 w-4 text-primary"
                                        aria-hidden="true"
                                    />
                                    <span>Repeat Requirement</span>
                                </DropdownMenuItem>
                            )}

                            {row.can_cancel && (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        onClick={() => onCancel(row)}
                                        className="cursor-pointer gap-2 text-rose-700 focus:text-rose-700 dark:text-rose-400 dark:focus:text-rose-400"
                                    >
                                        <Ban
                                            className="h-4 w-4"
                                            aria-hidden="true"
                                        />
                                        <span>Cancel Requirement</span>
                                    </DropdownMenuItem>
                                </>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </TableCell>
        </TableRow>
    );
}
