import {
    Ban,
    CheckCircle2,
    Clock,
    Copy,
    Edit3,
    MoreHorizontal,
    PauseCircle,
    PlayCircle,
    RotateCcw,
    Send,
    Undo2,
    Users,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { RequirementDetail } from '@/types/recruitment';
import {
    requirementPrimaryWorkflowActionLabel,
    resolveRequirementPrimaryWorkflowAction,
} from '../../lib/requirement-actions';
import {
    isRequirementHoldActionVisible,
    isRequirementResumeActionVisible,
} from '../../lib/requirement-hold-feature';
import { resolveActiveRecruitmentDurationDisplay } from '../../lib/requirement-recruitment-clock';
import {
    RequirementDeadlineBadge,
    RequirementPriorityBadge,
    RequirementStatusBadge,
} from '../requirement-status-badge';

type Props = {
    requirement: RequirementDetail;
    processing?: boolean;
    onEdit: () => void;
    onSubmit: () => void;
    onApprove: () => void;
    onReturn: () => void;
    onResubmit: () => void;
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
    processing = false,
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
    const showHold = isRequirementHoldActionVisible(requirement.can_hold);
    const showResume = isRequirementResumeActionVisible({
        canResume: requirement.can_resume,
        status: requirement.status,
    });
    const primaryActionKey = resolveRequirementPrimaryWorkflowAction({
        can_approve: requirement.can_approve,
        can_submit: requirement.can_submit,
        can_resubmit: requirement.can_resubmit,
        can_resume: showResume,
        can_fill: requirement.can_fill,
    });
    const durationDisplay = resolveActiveRecruitmentDurationDisplay({
        clockState: requirement.recruitment_clock_state,
        activeSeconds: requirement.active_recruitment_seconds,
        isEstimated: requirement.duration_is_estimated,
        estimateNote: requirement.duration_estimate_note,
    });

    const primaryAction = (() => {
        if (primaryActionKey === null) {
            return null;
        }

        const label = requirementPrimaryWorkflowActionLabel(primaryActionKey);
        const onClick = {
            approve: onApprove,
            submit: onSubmit,
            resubmit: onResubmit,
            resume: onResume,
            fill: onFill,
        }[primaryActionKey];

        const icon = {
            approve: <CheckCircle2 className="h-4 w-4" aria-hidden="true" />,
            submit: <Send className="h-4 w-4" aria-hidden="true" />,
            resubmit: <Send className="h-4 w-4" aria-hidden="true" />,
            resume: <PlayCircle className="h-4 w-4" aria-hidden="true" />,
            fill: <CheckCircle2 className="h-4 w-4" aria-hidden="true" />,
        }[primaryActionKey];

        const className = {
            approve:
                'w-full gap-1.5 bg-emerald-600 text-white hover:bg-emerald-700 sm:w-auto',
            submit: 'w-full gap-1.5 sm:w-auto',
            resubmit: 'w-full gap-1.5 sm:w-auto',
            resume: 'w-full gap-1.5 bg-emerald-600 text-white hover:bg-emerald-700 sm:w-auto',
            fill: 'w-full gap-1.5 bg-sky-600 text-white hover:bg-sky-700 sm:w-auto',
        }[primaryActionKey];

        return (
            <Button
                size="sm"
                disabled={processing}
                onClick={onClick}
                className={className}
                data-primary-workflow-action={primaryActionKey}
            >
                {icon}
                {label}
            </Button>
        );
    })();

    const hasSecondaryActions =
        requirement.can_edit ||
        requirement.can_return ||
        requirement.can_extend ||
        requirement.can_change_headcount ||
        showHold ||
        requirement.can_reopen ||
        requirement.can_repeat ||
        requirement.can_cancel;

    return (
        <Card className="overflow-hidden border-border/70 shadow-xs">
            <CardHeader className="pb-3">
                <CardTitle className="text-base font-semibold">
                    Status & actions
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4 pt-0">
                <div className="flex flex-wrap items-center gap-2">
                    <RequirementStatusBadge
                        status={requirement.status}
                        label={requirement.status_label}
                    />
                    <RequirementPriorityBadge priority={requirement.priority} />
                    <RequirementDeadlineBadge
                        health={requirement.deadline_health}
                        label={requirement.days_label}
                    />
                </div>

                <div className="grid grid-cols-2 gap-3 rounded-lg border border-border/60 bg-muted/30 p-3">
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Staffing target
                        </p>
                        <p className="mt-1 text-xl font-semibold tabular-nums">
                            {requirement.total_headcount}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {requirement.positions_count}{' '}
                            {requirement.positions_count === 1
                                ? 'role'
                                : 'roles'}
                        </p>
                    </div>
                    <div>
                        <p className="text-xs text-muted-foreground">
                            Deadline
                        </p>
                        <p className="mt-1 text-sm font-semibold">
                            {requirement.required_by_date_formatted ||
                                'No deadline'}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            {requirement.assigned_recruiter_name
                                ? `Recruiter: ${requirement.assigned_recruiter_name}`
                                : 'Recruiter: Unassigned'}
                        </p>
                        {requirement.can_extend ? (
                            <Button
                                type="button"
                                variant="link"
                                size="sm"
                                disabled={processing}
                                onClick={onExtend}
                                className="mt-1 h-auto px-0 text-xs"
                            >
                                {requirement.deadline_extension_mode ===
                                'request'
                                    ? 'Request deadline extension'
                                    : 'Extend deadline'}
                            </Button>
                        ) : null}
                    </div>
                    <div className="col-span-2 border-t border-border/50 pt-2">
                        <p className="text-xs text-muted-foreground">
                            Active recruitment
                        </p>
                        <p className="mt-1 text-sm font-semibold">
                            {durationDisplay.label}
                        </p>
                    </div>
                </div>

                {(primaryAction ||
                    requirement.can_return ||
                    hasSecondaryActions) && (
                    <div className="flex flex-wrap items-center gap-2 pt-1">
                        {primaryAction}

                        {requirement.can_return ? (
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={processing}
                                onClick={onReturn}
                                className="w-full gap-1.5 border-orange-500/40 text-orange-700 hover:bg-orange-500/10 sm:w-auto dark:text-orange-400"
                            >
                                <Undo2 className="h-4 w-4" aria-hidden="true" />
                                Return
                            </Button>
                        ) : null}

                        {hasSecondaryActions && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={processing}
                                        className={cn(
                                            'gap-1.5',
                                            !primaryAction &&
                                                !requirement.can_return &&
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
                                            <span>Edit requirement</span>
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
                                            <span>
                                                {requirement.deadline_extension_mode ===
                                                'request'
                                                    ? 'Request deadline extension'
                                                    : 'Extend deadline'}
                                            </span>
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
                                            <span>Revise headcount</span>
                                        </DropdownMenuItem>
                                    )}

                                    {showHold ? (
                                        <DropdownMenuItem
                                            onClick={onHold}
                                            className="cursor-pointer gap-2"
                                        >
                                            <PauseCircle
                                                className="h-4 w-4 text-amber-500"
                                                aria-hidden="true"
                                            />
                                            <span>Put on hold</span>
                                        </DropdownMenuItem>
                                    ) : null}

                                    {requirement.can_reopen && (
                                        <DropdownMenuItem
                                            onClick={onReopen}
                                            className="cursor-pointer gap-2"
                                        >
                                            <RotateCcw
                                                className="h-4 w-4 text-primary"
                                                aria-hidden="true"
                                            />
                                            <span>Reopen requirement</span>
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
                                            <span>Repeat requirement</span>
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
                                                <span>Cancel requirement</span>
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
