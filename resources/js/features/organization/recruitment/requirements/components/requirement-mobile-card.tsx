import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Ban,
    CheckCircle2,
    Clock,
    Copy,
    Edit3,
    Flame,
    MoreHorizontal,
    PauseCircle,
    PlayCircle,
    RotateCcw,
    Users,
} from 'lucide-react';
import RequirementController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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

function StatusBadge({ row }: { row: RequirementIndexRow }) {
    return (
        <Badge
            variant="outline"
            className={cn(
                'px-2 py-0 text-[10px] font-semibold',
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
    );
}

function DeadlineHealthBadge({ row }: { row: RequirementIndexRow }) {
    if (!row.deadline_health) {
        return null;
    }

    return (
        <Badge
            variant="outline"
            className={cn(
                'gap-1 px-1.5 py-0 text-[10px] font-medium',
                row.deadline_health === 'overdue' &&
                    'border-rose-500/30 bg-rose-500/10 text-rose-500',
                row.deadline_health === 'due_soon' &&
                    'border-amber-500/30 bg-amber-500/10 text-amber-500',
                row.deadline_health === 'on_track' &&
                    'border-emerald-500/30 bg-emerald-500/10 text-emerald-500',
            )}
        >
            {row.deadline_health === 'overdue' && (
                <AlertTriangle className="h-2.5 w-2.5" aria-hidden="true" />
            )}
            {row.deadline_health === 'due_soon' && (
                <Clock className="h-2.5 w-2.5" aria-hidden="true" />
            )}
            {row.days_label}
        </Badge>
    );
}

export function RequirementMobileCard({
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

    // Build the one contextual primary action
    const primaryAction = (() => {
        if (row.next_action === 'open' && row.can_open) {
            return (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => onOpen(row)}
                    className="h-7 gap-1 border-emerald-500/40 text-xs text-emerald-500 hover:bg-emerald-500/10"
                >
                    <PlayCircle className="h-3.5 w-3.5" aria-hidden="true" />
                    Open
                </Button>
            );
        }

        if (row.next_action === 'resume' && row.can_resume) {
            return (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => onResume(row)}
                    className="h-7 gap-1 border-emerald-500/40 text-xs text-emerald-500 hover:bg-emerald-500/10"
                >
                    <PlayCircle className="h-3.5 w-3.5" aria-hidden="true" />
                    Resume
                </Button>
            );
        }

        if (row.next_action === 'fill' && row.can_fill) {
            return (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => onFill(row)}
                    className="h-7 gap-1 border-sky-500/40 text-xs text-sky-500 hover:bg-sky-500/10"
                >
                    <CheckCircle2 className="h-3.5 w-3.5" aria-hidden="true" />
                    Mark Filled
                </Button>
            );
        }

        if (row.next_action === 'extend' && row.can_extend) {
            return (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => onExtend(row)}
                    className="h-7 gap-1 border-amber-500/40 text-xs text-amber-500 hover:bg-amber-500/10"
                >
                    <Clock className="h-3.5 w-3.5" aria-hidden="true" />
                    Extend
                </Button>
            );
        }

        if (row.next_action === 'repeat' && row.can_repeat) {
            return (
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() => onRepeat(row)}
                    className="h-7 gap-1 border-primary/40 text-xs text-primary hover:bg-primary/10"
                >
                    <Copy className="h-3.5 w-3.5" aria-hidden="true" />
                    Repeat
                </Button>
            );
        }

        return null;
    })();

    // Two-letter initials (first + last name initial)
    const recruiterInitials = (() => {
        const name = row.assigned_recruiter_name;

        if (!name) {
            return null;
        }

        const parts = name.trim().split(/\s+/);

        if (parts.length >= 2) {
            return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
        }

        return parts[0].slice(0, 2).toUpperCase();
    })();

    return (
        <div className="rounded-xl border border-border/70 bg-card p-3 shadow-xs transition-colors hover:border-border dark:bg-card/60">
            {/* Row 1: req number + priority | status badge */}
            <div className="flex items-center justify-between gap-2">
                <div className="flex items-center gap-1.5">
                    {row.priority === 'urgent' && (
                        <Flame
                            className="h-3.5 w-3.5 fill-rose-500 text-rose-500"
                            aria-label="Urgent priority"
                        />
                    )}
                    <Link
                        href={showUrl}
                        className="font-mono text-sm font-bold text-foreground hover:text-primary hover:underline focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        {row.requirement_number}
                    </Link>
                    {row.repeated_from_number && (
                        <span className="text-[10px] text-muted-foreground">
                            <Copy
                                className="inline h-2.5 w-2.5"
                                aria-hidden="true"
                            />{' '}
                            {row.repeated_from_number}
                        </span>
                    )}
                </div>
                <StatusBadge row={row} />
            </div>

            {/* Row 2: Client / Project */}
            <div className="mt-1.5">
                <span
                    className="block truncate text-xs font-semibold text-foreground"
                    title={row.client_name}
                >
                    {row.client_name}
                </span>
                {(row.project_title || row.location) && (
                    <span className="block truncate text-[11px] text-muted-foreground">
                        {row.project_title || ''}
                        {row.location ? ` • ${row.location}` : ''}
                    </span>
                )}
            </div>

            {/* Row 3: Positions summary */}
            {row.positions_summary.length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1">
                    {row.positions_summary.slice(0, 2).map((p) => (
                        <span
                            key={p.id}
                            className="inline-flex max-w-[140px] items-center truncate rounded border border-border/60 bg-muted/40 px-1.5 py-0.5 text-[10px] font-medium text-foreground/80"
                            title={`${p.position_title} — ${p.required_headcount} headcount`}
                        >
                            {p.position_title}
                            <span className="ml-1 font-semibold text-muted-foreground">
                                ×{p.required_headcount}
                            </span>
                        </span>
                    ))}
                    {row.positions_summary.length > 2 && (
                        <span className="inline-flex items-center rounded border border-border/40 bg-muted/20 px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                            +{row.positions_summary.length - 2} more
                        </span>
                    )}
                </div>
            )}

            {/* Row 4: Headcount | Deadline health */}
            <div className="mt-2 flex items-center gap-2">
                <span className="flex items-center gap-1 text-[11px] font-semibold text-foreground">
                    <Users
                        className="h-3 w-3 text-muted-foreground"
                        aria-hidden="true"
                    />
                    {row.total_headcount}{' '}
                    <span className="font-normal text-muted-foreground">
                        {row.total_headcount === 1 ? 'headcount' : 'headcounts'}
                    </span>
                </span>
                <DeadlineHealthBadge row={row} />
            </div>

            {/* Row 5: Recruiter | Required By | Actions */}
            <div className="mt-2.5 flex items-center justify-between gap-2">
                <div className="flex items-center gap-1.5">
                    {recruiterInitials ? (
                        <span
                            className="flex h-5 w-5 items-center justify-center rounded-full bg-primary/10 text-[9px] font-bold text-primary"
                            title={row.assigned_recruiter_name ?? undefined}
                            aria-label={`Assigned to ${row.assigned_recruiter_name}`}
                        >
                            {recruiterInitials}
                        </span>
                    ) : (
                        <span className="text-[10px] text-muted-foreground/60">
                            Unassigned
                        </span>
                    )}
                    {row.required_by_date_formatted && (
                        <span className="text-[11px] text-muted-foreground">
                            Due {row.required_by_date_formatted}
                        </span>
                    )}
                </div>

                {/* Actions */}
                <div
                    className="flex items-center gap-1"
                    onClick={(e) => e.stopPropagation()}
                >
                    {primaryAction}

                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="sm"
                                className="h-7 w-7 p-0 text-muted-foreground hover:text-foreground"
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
                                    <Edit3
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
                                    <span>Edit Requisition</span>
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
                                        className="h-4 w-4 text-amber-500"
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
                                        className="h-4 w-4 text-emerald-500"
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
                                        className="h-4 w-4 text-sky-500"
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
                                        className="cursor-pointer gap-2 text-rose-500 focus:text-rose-500"
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
            </div>
        </div>
    );
}
