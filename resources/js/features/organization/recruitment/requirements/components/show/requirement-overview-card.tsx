import {
    AlertTriangle,
    Ban,
    CheckCircle2,
    Clock,
    Copy,
    Edit3,
    Flame,
    PauseCircle,
    PlayCircle,
    RotateCcw,
    Users,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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

    return (
        <Card className="overflow-hidden glass-card border-border/70">
            <CardContent className="p-6">
                <div className="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
                    {/* Progress & Headcount info */}
                    <div className="min-w-0 flex-1 space-y-3">
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
                                    <Flame className="h-3 w-3 fill-rose-500" />
                                    Urgent Requisition
                                </Badge>
                            )}

                            {requirement.deadline_health && (
                                <Badge
                                    variant="outline"
                                    className={cn(
                                        'gap-1 text-xs font-medium',
                                        requirement.deadline_health ===
                                            'overdue' &&
                                            'border-rose-500/30 bg-rose-500/10 text-rose-500',
                                        requirement.deadline_health ===
                                            'due_soon' &&
                                            'border-amber-500/30 bg-amber-500/10 text-amber-500',
                                        requirement.deadline_health ===
                                            'on_track' &&
                                            'border-emerald-500/30 bg-emerald-500/10 text-emerald-500',
                                    )}
                                >
                                    {requirement.deadline_health ===
                                        'overdue' && (
                                        <AlertTriangle className="h-3 w-3" />
                                    )}
                                    {requirement.deadline_health ===
                                        'due_soon' && (
                                        <Clock className="h-3 w-3" />
                                    )}
                                    {requirement.days_label}
                                </Badge>
                            )}

                            {isTargetReached &&
                                requirement.status === 'open' && (
                                    <Badge className="bg-emerald-500 font-semibold text-white">
                                        Target Reached
                                    </Badge>
                                )}
                        </div>

                        {/* Headcount numbers & progress bar */}
                        <div className="space-y-1.5 pt-1">
                            <div className="flex items-center justify-between text-xs">
                                <span className="flex items-center gap-1.5 text-muted-foreground">
                                    <Users className="h-3.5 w-3.5" />
                                    Total Headcount Required:{' '}
                                    <strong className="text-foreground">
                                        {requirement.progress.target}
                                    </strong>
                                </span>
                                <span className="font-semibold text-foreground">
                                    {requirement.progress.filled} of{' '}
                                    {requirement.progress.target} Filled (
                                    {requirement.progress.percentage}%)
                                </span>
                            </div>
                            <div className="h-2 w-full overflow-hidden rounded-full bg-muted/60">
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
                    </div>

                    {/* Action buttons */}
                    <div className="flex flex-wrap items-center gap-2 lg:justify-end">
                        {requirement.can_open && (
                            <Button
                                size="sm"
                                onClick={onOpen}
                                className="gap-1.5 bg-emerald-600 text-white hover:bg-emerald-700"
                            >
                                <PlayCircle className="h-4 w-4" />
                                Open Requisition
                            </Button>
                        )}

                        {requirement.can_resume && (
                            <Button
                                size="sm"
                                onClick={onResume}
                                className="gap-1.5 bg-emerald-600 text-white hover:bg-emerald-700"
                            >
                                <PlayCircle className="h-4 w-4" />
                                Resume Requisition
                            </Button>
                        )}

                        {requirement.can_fill && (
                            <Button
                                size="sm"
                                onClick={onFill}
                                className="gap-1.5 bg-sky-600 text-white hover:bg-sky-700"
                            >
                                <CheckCircle2 className="h-4 w-4" />
                                Mark as Filled
                            </Button>
                        )}

                        {requirement.can_edit && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={onEdit}
                                className="gap-1.5"
                            >
                                <Edit3 className="h-4 w-4" />
                                Edit
                            </Button>
                        )}

                        {requirement.can_extend && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={onExtend}
                                className="gap-1.5"
                            >
                                <Clock className="h-4 w-4" />
                                Extend Deadline
                            </Button>
                        )}

                        {requirement.can_change_headcount && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={onChangeHeadcount}
                                className="gap-1.5"
                            >
                                <Users className="h-4 w-4" />
                                Revise Headcount
                            </Button>
                        )}

                        {requirement.can_hold && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={onHold}
                                className="gap-1.5 text-amber-500 hover:bg-amber-500/10 hover:text-amber-600"
                            >
                                <PauseCircle className="h-4 w-4" />
                                Hold
                            </Button>
                        )}

                        {requirement.can_reopen && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={onReopen}
                                className="gap-1.5 text-primary hover:bg-primary/10"
                            >
                                <RotateCcw className="h-4 w-4" />
                                Reopen
                            </Button>
                        )}

                        {requirement.can_repeat && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={onRepeat}
                                className="gap-1.5"
                            >
                                <Copy className="h-4 w-4" />
                                Repeat
                            </Button>
                        )}

                        {requirement.can_cancel && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={onCancel}
                                className="gap-1.5 text-rose-500 hover:bg-rose-500/10 hover:text-rose-600"
                            >
                                <Ban className="h-4 w-4" />
                                Cancel
                            </Button>
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
