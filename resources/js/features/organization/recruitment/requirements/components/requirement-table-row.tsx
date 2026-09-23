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
        <TableRow className={cn(dataTableBodyRowClass(), 'group')}>
            {/* Requirement Number & Tag */}
            <TableCell
                className={cn(dataTableCellPrimaryClass(), 'whitespace-nowrap')}
            >
                <div className="flex flex-col gap-1">
                    <Link
                        href={showUrl}
                        className="font-mono text-sm font-bold text-foreground transition-colors group-hover:text-primary hover:underline"
                    >
                        {row.requirement_number}
                    </Link>
                    {row.repeated_from_number && (
                        <div className="flex items-center gap-1 text-[10px] text-muted-foreground">
                            <Copy className="h-3 w-3 text-muted-foreground/70" />
                            <span>From {row.repeated_from_number}</span>
                        </div>
                    )}
                </div>
            </TableCell>

            {/* Client & Project */}
            <TableCell className={dataTableCellClass()}>
                <div className="flex max-w-[220px] flex-col gap-0.5">
                    <span
                        className="truncate font-semibold text-foreground"
                        title={row.client_name}
                    >
                        {row.client_name}
                    </span>
                    {(row.project_title || row.location) && (
                        <span
                            className="truncate text-xs text-muted-foreground"
                            title={`${row.project_title || ''} ${row.location ? `(${row.location})` : ''}`}
                        >
                            {row.project_title || ''}
                            {row.location ? ` • ${row.location}` : ''}
                        </span>
                    )}
                </div>
            </TableCell>

            {/* Positions & Headcount */}
            <TableCell className={dataTableCellClass()}>
                <div className="flex max-w-[240px] flex-col gap-1.5">
                    <div className="flex items-center gap-2">
                        <Badge
                            variant="secondary"
                            className="px-2 py-0 text-[11px] font-bold"
                        >
                            {row.total_headcount}{' '}
                            {row.total_headcount === 1
                                ? 'Headcount'
                                : 'Headcounts'}
                        </Badge>
                        <span className="text-[11px] text-muted-foreground">
                            ({row.positions_count}{' '}
                            {row.positions_count === 1 ? 'role' : 'roles'})
                        </span>
                    </div>

                    <div className="flex flex-wrap gap-1">
                        {row.positions_summary.slice(0, 2).map((p) => (
                            <span
                                key={p.id}
                                className="inline-flex max-w-[130px] items-center truncate rounded-md border border-border/80 bg-muted/40 px-1.5 py-0.5 text-[10px] font-medium text-foreground/90"
                                title={`${p.position_title} (req: ${p.required_headcount})`}
                            >
                                {p.position_title}
                                <span className="ml-1 font-semibold text-muted-foreground">
                                    ×{p.required_headcount}
                                </span>
                            </span>
                        ))}
                        {row.positions_summary.length > 2 && (
                            <span className="inline-flex items-center rounded-md border border-border/60 bg-muted/20 px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                                +{row.positions_summary.length - 2} more
                            </span>
                        )}
                    </div>
                </div>
            </TableCell>

            {/* Priority */}
            <TableCell
                className={cn(dataTableCellClass(), 'whitespace-nowrap')}
            >
                {row.priority === 'urgent' ? (
                    <Badge
                        variant="outline"
                        className="gap-1 border-rose-500/30 bg-rose-500/10 px-2 py-0.5 font-bold text-rose-500"
                    >
                        <Flame className="h-3 w-3 animate-pulse fill-rose-500 text-rose-500" />
                        Urgent
                    </Badge>
                ) : (
                    <Badge
                        variant="outline"
                        className="border-border/60 px-2 py-0.5 font-medium text-muted-foreground"
                    >
                        Normal
                    </Badge>
                )}
            </TableCell>

            {/* Required By & Deadline Health */}
            <TableCell
                className={cn(dataTableCellClass(), 'whitespace-nowrap')}
            >
                <div className="flex flex-col gap-1">
                    <span className="text-xs font-semibold text-foreground">
                        {row.required_by_date_formatted || '—'}
                    </span>
                    {row.deadline_health && (
                        <Badge
                            variant="outline"
                            className={cn(
                                'w-fit gap-1 px-1.5 py-0 text-[10px] font-medium',
                                row.deadline_health === 'overdue' &&
                                    'border-rose-500/30 bg-rose-500/10 text-rose-500',
                                row.deadline_health === 'due_soon' &&
                                    'border-amber-500/30 bg-amber-500/10 text-amber-500',
                                row.deadline_health === 'on_track' &&
                                    'border-emerald-500/30 bg-emerald-500/10 text-emerald-500',
                            )}
                        >
                            {row.deadline_health === 'overdue' && (
                                <AlertTriangle className="h-2.5 w-2.5" />
                            )}
                            {row.deadline_health === 'due_soon' && (
                                <Clock className="h-2.5 w-2.5" />
                            )}
                            {row.days_label}
                        </Badge>
                    )}
                </div>
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
                            'border-emerald-500/30 bg-emerald-500/10 text-emerald-500',
                        row.status === 'draft' &&
                            'border-zinc-500/30 bg-zinc-500/10 text-zinc-400',
                        row.status === 'on_hold' &&
                            'border-amber-500/30 bg-amber-500/10 text-amber-500',
                        row.status === 'completed' &&
                            'border-sky-500/30 bg-sky-500/10 text-sky-500',
                        row.status === 'cancelled' &&
                            'border-rose-500/30 bg-rose-500/10 text-rose-500',
                    )}
                >
                    {row.status_label}
                </Badge>
            </TableCell>

            {/* Assigned Recruiter */}
            <TableCell
                className={cn(dataTableCellClass(), 'whitespace-nowrap')}
            >
                {row.assigned_recruiter_name ? (
                    <div className="flex items-center gap-1.5">
                        <div className="flex h-6 w-6 items-center justify-center rounded-full bg-primary/10 text-[10px] font-bold text-primary">
                            {row.assigned_recruiter_name
                                .charAt(0)
                                .toUpperCase()}
                        </div>
                        <span className="max-w-[120px] truncate text-xs font-medium text-foreground">
                            {row.assigned_recruiter_name}
                        </span>
                    </div>
                ) : (
                    <span className="text-xs text-muted-foreground/70">
                        Unassigned
                    </span>
                )}
            </TableCell>

            {/* Actions: Contextual Primary + Overflow */}
            <TableCell
                className={cn(
                    dataTableCellClass(),
                    'text-right whitespace-nowrap',
                )}
            >
                <div className="flex items-center justify-end gap-1.5">
                    {/* Contextual primary button */}
                    {row.next_action === 'open' && row.can_open && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => onOpen(row)}
                            className="h-8 gap-1 border-emerald-500/40 text-xs text-emerald-500 hover:bg-emerald-500/10"
                        >
                            <PlayCircle className="h-3.5 w-3.5" />
                            Open
                        </Button>
                    )}
                    {row.next_action === 'resume' && row.can_resume && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => onResume(row)}
                            className="h-8 gap-1 border-emerald-500/40 text-xs text-emerald-500 hover:bg-emerald-500/10"
                        >
                            <PlayCircle className="h-3.5 w-3.5" />
                            Resume
                        </Button>
                    )}
                    {row.next_action === 'fill' && row.can_fill && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => onFill(row)}
                            className="h-8 gap-1 border-sky-500/40 text-xs text-sky-500 hover:bg-sky-500/10"
                        >
                            <CheckCircle2 className="h-3.5 w-3.5" />
                            Mark Filled
                        </Button>
                    )}
                    {row.next_action === 'extend' && row.can_extend && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => onExtend(row)}
                            className="h-8 gap-1 border-amber-500/40 text-xs text-amber-500 hover:bg-amber-500/10"
                        >
                            <Clock className="h-3.5 w-3.5" />
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
                            <Copy className="h-3.5 w-3.5" />
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
                            >
                                <span className="sr-only">Open menu</span>
                                <MoreHorizontal className="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-48">
                            <DropdownMenuItem asChild>
                                <Link
                                    href={showUrl}
                                    className="cursor-pointer gap-2"
                                >
                                    <Eye className="h-4 w-4 text-muted-foreground" />
                                    <span>View Details</span>
                                </Link>
                            </DropdownMenuItem>

                            {row.can_edit && (
                                <DropdownMenuItem
                                    onClick={() => onEdit(row)}
                                    className="cursor-pointer gap-2"
                                >
                                    <Edit3 className="h-4 w-4 text-muted-foreground" />
                                    <span>Edit Requisition</span>
                                </DropdownMenuItem>
                            )}

                            {row.can_change_headcount && (
                                <DropdownMenuItem
                                    onClick={() => onChangeHeadcount(row)}
                                    className="cursor-pointer gap-2"
                                >
                                    <Users className="h-4 w-4 text-muted-foreground" />
                                    <span>Revise Headcount</span>
                                </DropdownMenuItem>
                            )}

                            {row.can_extend && (
                                <DropdownMenuItem
                                    onClick={() => onExtend(row)}
                                    className="cursor-pointer gap-2"
                                >
                                    <Clock className="h-4 w-4 text-muted-foreground" />
                                    <span>Extend Deadline</span>
                                </DropdownMenuItem>
                            )}

                            {row.can_hold && (
                                <DropdownMenuItem
                                    onClick={() => onHold(row)}
                                    className="cursor-pointer gap-2"
                                >
                                    <PauseCircle className="h-4 w-4 text-amber-500" />
                                    <span>Hold Requirement</span>
                                </DropdownMenuItem>
                            )}

                            {row.can_resume && (
                                <DropdownMenuItem
                                    onClick={() => onResume(row)}
                                    className="cursor-pointer gap-2"
                                >
                                    <PlayCircle className="h-4 w-4 text-emerald-500" />
                                    <span>Resume Requirement</span>
                                </DropdownMenuItem>
                            )}

                            {row.can_fill && (
                                <DropdownMenuItem
                                    onClick={() => onFill(row)}
                                    className="cursor-pointer gap-2"
                                >
                                    <CheckCircle2 className="h-4 w-4 text-sky-500" />
                                    <span>Mark as Filled</span>
                                </DropdownMenuItem>
                            )}

                            {row.can_reopen && (
                                <DropdownMenuItem
                                    onClick={() => onReopen(row)}
                                    className="cursor-pointer gap-2"
                                >
                                    <RotateCcw className="h-4 w-4 text-primary" />
                                    <span>Reopen Requirement</span>
                                </DropdownMenuItem>
                            )}

                            {row.can_repeat && (
                                <DropdownMenuItem
                                    onClick={() => onRepeat(row)}
                                    className="cursor-pointer gap-2"
                                >
                                    <Copy className="h-4 w-4 text-primary" />
                                    <span>Repeat Requirement</span>
                                </DropdownMenuItem>
                            )}

                            {row.can_cancel && (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        onClick={() => onCancel(row)}
                                        className="cursor-pointer gap-2 text-rose-500 focus:text-rose-500"
                                    >
                                        <Ban className="h-4 w-4" />
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
