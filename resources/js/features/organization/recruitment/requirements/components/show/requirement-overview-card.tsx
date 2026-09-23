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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { RequirementDetail } from '@/types/recruitment';

type Props = {
    requirement: RequirementDetail;
    onEdit: () => void;
    onOpen: () => void;
    onHold: () => void;
    onResume: () => void;
    onExtend: () => void;
    onChangeHeadcount: () => void;
    onFill: () => void;
    onCancel: () => void;
    onReopen: () => void;
    onRepeat: () => void;
};

export function RequirementOverviewCard({
    requirement,
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
    const isTargetReached = requirement.progress.is_target_reached;

    // Determine the single primary action
    const primaryAction = (() => {
        if (requirement.can_open) {
            return (
                <Button
                    size="sm"
                    onClick={onOpen}
                    className="w-full gap-1.5 bg-emerald-600 text-white hover:bg-emerald-700 sm:w-auto"
                >
                    <PlayCircle className="h-4 w-4" aria-hidden="true" />
                    Open Requirement
                </Button>
            );
        }

        if (requirement.can_resume) {
            return (
                <Button
                    size="sm"
                    onClick={onResume}
                    className="w-full gap-1.5 bg-emerald-600 text-white hover:bg-emerald-700 sm:w-auto"
                >
                    <PlayCircle className="h-4 w-4" aria-hidden="true" />
                    Resume Requirement
                </Button>
            );
        }

        if (requirement.can_fill) {
            return (
                <Button
                    size="sm"
                    onClick={onFill}
                    className="w-full gap-1.5 bg-sky-600 text-white hover:bg-sky-700 sm:w-auto"
                >
                    <CheckCircle2 className="h-4 w-4" aria-hidden="true" />
                    Mark as Filled
                </Button>
            );
        }

        // For completed/cancelled: informational (no primary destructive action)
        return null;
    })();

    // Secondary actions in overflow menu
    const hasSecondaryActions =
        requirement.can_edit ||
        requirement.can_extend ||
        requirement.can_change_headcount ||
        requirement.can_hold ||
        requirement.can_reopen ||
        requirement.can_repeat ||
        requirement.can_cancel;

    return (
        <Card className="overflow-hidden glass-card border-border/70">
            <CardContent className="space-y-4 p-5">
                {/* Status badges row */}
                <div className="flex flex-wrap items-center gap-2">
                    <Badge
                        variant="outline"
                        className={cn(
                            'px-2.5 py-1 text-xs font-semibold',
                            requirement.status === 'open' &&
                                'border-emerald-500/30 bg-emerald-500/10 text-emerald-500',
                            requirement.status === 'draft' &&
                                'border-zinc-500/30 bg-zinc-500/10 text-zinc-400',
                            requirement.status === 'on_hold' &&
                                'border-amber-500/30 bg-amber-500/10 text-amber-500',
                            requirement.status === 'completed' &&
                                'border-sky-500/30 bg-sky-500/10 text-sky-500',
                            requirement.status === 'cancelled' &&
                                'border-rose-500/30 bg-rose-500/10 text-rose-500',
                        )}
                    >
                        {requirement.status_label}
                    </Badge>

                    {requirement.priority === 'urgent' && (
                        <Badge
                            variant="outline"
                            className="gap-1 border-rose-500/30 bg-rose-500/10 font-bold text-rose-500"
                        >
                            <Flame
                                className="h-3 w-3 fill-rose-500"
                                aria-hidden="true"
                            />
                            Urgent
                        </Badge>
                    )}

                    {requirement.deadline_health && (
                        <Badge
                            variant="outline"
                            className={cn(
                                'gap-1 text-xs font-medium',
                                requirement.deadline_health === 'overdue' &&
                                    'border-rose-500/30 bg-rose-500/10 text-rose-500',
                                requirement.deadline_health === 'due_soon' &&
                                    'border-amber-500/30 bg-amber-500/10 text-amber-500',
                                requirement.deadline_health === 'on_track' &&
                                    'border-emerald-500/30 bg-emerald-500/10 text-emerald-500',
                            )}
                        >
                            {requirement.deadline_health === 'overdue' && (
                                <AlertTriangle
                                    className="h-3 w-3"
                                    aria-hidden="true"
                                />
                            )}
                            {requirement.deadline_health === 'due_soon' && (
                                <Clock className="h-3 w-3" aria-hidden="true" />
                            )}
                            {requirement.days_label}
                        </Badge>
                    )}

                    {isTargetReached && requirement.status === 'open' && (
                        <Badge className="bg-emerald-500 font-semibold text-white">
                            Target Reached
                        </Badge>
                    )}
                </div>

                {/* Headcount progress */}
                <div className="space-y-1.5">
                    <div className="flex items-center justify-between text-xs">
                        <span className="flex items-center gap-1.5 text-muted-foreground">
                            <Users className="h-3.5 w-3.5" aria-hidden="true" />
                            Total Headcount Required:{' '}
                            <strong className="text-foreground">
                                {requirement.progress.target}
                            </strong>
                        </span>
                        <span className="font-semibold text-foreground tabular-nums">
                            {requirement.progress.filled} /{' '}
                            {requirement.progress.target} (
                            {requirement.progress.percentage}%)
                        </span>
                    </div>
                    <div
                        className="h-2 w-full overflow-hidden rounded-full bg-muted/60"
                        role="progressbar"
                        aria-valuenow={requirement.progress.percentage}
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-label={`${requirement.progress.percentage}% headcount filled`}
                    >
                        <div
                            className={cn(
                                'h-full transition-all duration-500',
                                requirement.progress.percentage >= 100
                                    ? 'bg-emerald-500'
                                    : 'bg-primary',
                            )}
                            style={{
                                width: `${Math.min(100, requirement.progress.percentage)}%`,
                            }}
                        />
                    </div>
                </div>

                {/* Required by date */}
                {requirement.required_by_date_formatted && (
                    <div className="flex items-center justify-between rounded-lg border border-border/50 bg-muted/20 px-3 py-2 text-xs">
                        <span className="text-muted-foreground">
                            Required by
                        </span>
                        <span className="font-semibold text-foreground">
                            {requirement.required_by_date_formatted}
                        </span>
                    </div>
                )}

                {/* Action row: primary + overflow menu */}
                {(primaryAction || hasSecondaryActions) && (
                    <div className="flex items-center gap-2 pt-1">
                        {primaryAction}

                        {hasSecondaryActions && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className={cn(
                                            'gap-1.5',
                                            !primaryAction &&
                                                'w-full sm:w-auto',
                                        )}
                                        aria-label="More actions"
                                    >
                                        <MoreHorizontal
                                            className="h-4 w-4"
                                            aria-hidden="true"
                                        />
                                        <span>More</span>
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="start"
                                    className="w-52"
                                >
                                    {requirement.can_edit && (
                                        <DropdownMenuItem
                                            onClick={onEdit}
                                            className="cursor-pointer gap-2"
                                        >
                                            <Edit3
                                                className="h-4 w-4 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                            <span>Edit Requisition</span>
                                        </DropdownMenuItem>
                                    )}

                                    {requirement.can_extend && (
                                        <DropdownMenuItem
                                            onClick={onExtend}
                                            className="cursor-pointer gap-2"
                                        >
                                            <Clock
                                                className="h-4 w-4 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                            <span>Extend Deadline</span>
                                        </DropdownMenuItem>
                                    )}

                                    {requirement.can_change_headcount && (
                                        <DropdownMenuItem
                                            onClick={onChangeHeadcount}
                                            className="cursor-pointer gap-2"
                                        >
                                            <Users
                                                className="h-4 w-4 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                            <span>Revise Headcount</span>
                                        </DropdownMenuItem>
                                    )}

                                    {requirement.can_hold && (
                                        <DropdownMenuItem
                                            onClick={onHold}
                                            className="cursor-pointer gap-2"
                                        >
                                            <PauseCircle
                                                className="h-4 w-4 text-amber-500"
                                                aria-hidden="true"
                                            />
                                            <span>Put On Hold</span>
                                        </DropdownMenuItem>
                                    )}

                                    {requirement.can_reopen && (
                                        <DropdownMenuItem
                                            onClick={onReopen}
                                            className="cursor-pointer gap-2"
                                        >
                                            <RotateCcw
                                                className="h-4 w-4 text-primary"
                                                aria-hidden="true"
                                            />
                                            <span>Reopen Requirement</span>
                                        </DropdownMenuItem>
                                    )}

                                    {requirement.can_repeat && (
                                        <DropdownMenuItem
                                            onClick={onRepeat}
                                            className="cursor-pointer gap-2"
                                        >
                                            <Copy
                                                className="h-4 w-4 text-primary"
                                                aria-hidden="true"
                                            />
                                            <span>Repeat Requirement</span>
                                        </DropdownMenuItem>
                                    )}

                                    {requirement.can_cancel && (
                                        <>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                onClick={onCancel}
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
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
