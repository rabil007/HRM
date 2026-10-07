import { Link } from '@inertiajs/react';
import {
    Ban,
    CheckCircle2,
    Clock,
    Copy,
    Edit3,
    Eye,
    MoreHorizontal,
    PauseCircle,
    PlayCircle,
    RotateCcw,
    Send,
    Undo2,
    Users,
} from 'lucide-react';
import RequirementController from '@/actions/App/Http/Controllers/Organization/Recruitment/RequirementController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { RequirementIndexRow } from '@/types/recruitment';
import { visibleRequirementActions } from '../lib/requirement-actions';

export type RequirementActionHandlers = {
    onEdit: (row: RequirementIndexRow) => void;
    onSubmit: (row: RequirementIndexRow) => void;
    onApprove: (row: RequirementIndexRow) => void;
    onReturn: (row: RequirementIndexRow) => void;
    onResubmit: (row: RequirementIndexRow) => void;
    onHold: (row: RequirementIndexRow) => void;
    onResume: (row: RequirementIndexRow) => void;
    onExtend: (row: RequirementIndexRow) => void;
    onChangeHeadcount: (row: RequirementIndexRow) => void;
    onFill: (row: RequirementIndexRow) => void;
    onCancel: (row: RequirementIndexRow) => void;
    onReopen: (row: RequirementIndexRow) => void;
    onRepeat: (row: RequirementIndexRow) => void;
};

type Props = RequirementActionHandlers & {
    row: RequirementIndexRow;
    triggerClassName?: string;
};

export function RequirementActionMenu({
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
    triggerClassName,
}: Props) {
    const showUrl = RequirementController.show.url(row.id);
    const actions = visibleRequirementActions(row);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="sm"
                    className={
                        triggerClassName ??
                        'h-8 w-8 p-0 text-muted-foreground hover:text-foreground'
                    }
                    aria-label={`More actions for ${row.requirement_number}`}
                >
                    <MoreHorizontal className="h-4 w-4" aria-hidden="true" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
                <DropdownMenuItem asChild>
                    <Link href={showUrl} className="cursor-pointer gap-2">
                        <Eye
                            className="h-4 w-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span>View details</span>
                    </Link>
                </DropdownMenuItem>

                {actions.canEdit ? (
                    <DropdownMenuItem
                        onClick={() => onEdit(row)}
                        className="cursor-pointer gap-2"
                    >
                        <Edit3
                            className="h-4 w-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span>Edit requirement</span>
                    </DropdownMenuItem>
                ) : null}

                {actions.canSubmit ? (
                    <DropdownMenuItem
                        onClick={() => onSubmit(row)}
                        className="cursor-pointer gap-2"
                    >
                        <Send
                            className="h-4 w-4 text-primary"
                            aria-hidden="true"
                        />
                        <span>Submit for approval</span>
                    </DropdownMenuItem>
                ) : null}

                {actions.canResubmit ? (
                    <DropdownMenuItem
                        onClick={() => onResubmit(row)}
                        className="cursor-pointer gap-2"
                    >
                        <Send
                            className="h-4 w-4 text-primary"
                            aria-hidden="true"
                        />
                        <span>Resubmit for approval</span>
                    </DropdownMenuItem>
                ) : null}

                {actions.canApprove ? (
                    <DropdownMenuItem
                        onClick={() => onApprove(row)}
                        className="cursor-pointer gap-2"
                    >
                        <CheckCircle2
                            className="h-4 w-4 text-emerald-700 dark:text-emerald-400"
                            aria-hidden="true"
                        />
                        <span>Approve requirement</span>
                    </DropdownMenuItem>
                ) : null}

                {actions.canReturn ? (
                    <DropdownMenuItem
                        onClick={() => onReturn(row)}
                        className="cursor-pointer gap-2"
                    >
                        <Undo2
                            className="h-4 w-4 text-orange-600 dark:text-orange-400"
                            aria-hidden="true"
                        />
                        <span>Return requirement</span>
                    </DropdownMenuItem>
                ) : null}

                {actions.canChangeHeadcount ? (
                    <DropdownMenuItem
                        onClick={() => onChangeHeadcount(row)}
                        className="cursor-pointer gap-2"
                    >
                        <Users
                            className="h-4 w-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span>Revise headcount</span>
                    </DropdownMenuItem>
                ) : null}

                {actions.canExtend ? (
                    <DropdownMenuItem
                        onClick={() => onExtend(row)}
                        className="cursor-pointer gap-2"
                    >
                        <Clock
                            className="h-4 w-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span>
                            {row.deadline_extension_mode === 'request'
                                ? 'Request deadline extension'
                                : 'Extend deadline'}
                        </span>
                    </DropdownMenuItem>
                ) : null}

                {actions.canHold ? (
                    <DropdownMenuItem
                        onClick={() => onHold(row)}
                        className="cursor-pointer gap-2"
                    >
                        <PauseCircle
                            className="h-4 w-4 text-amber-700 dark:text-amber-400"
                            aria-hidden="true"
                        />
                        <span>Put on hold</span>
                    </DropdownMenuItem>
                ) : null}

                {actions.canResume ? (
                    <DropdownMenuItem
                        onClick={() => onResume(row)}
                        className="cursor-pointer gap-2"
                    >
                        <PlayCircle
                            className="h-4 w-4 text-emerald-700 dark:text-emerald-400"
                            aria-hidden="true"
                        />
                        <span>Resume requirement</span>
                    </DropdownMenuItem>
                ) : null}

                {actions.canFill ? (
                    <DropdownMenuItem
                        onClick={() => onFill(row)}
                        className="cursor-pointer gap-2"
                    >
                        <CheckCircle2
                            className="h-4 w-4 text-sky-700 dark:text-sky-400"
                            aria-hidden="true"
                        />
                        <span>Mark as filled</span>
                    </DropdownMenuItem>
                ) : null}

                {actions.canReopen ? (
                    <DropdownMenuItem
                        onClick={() => onReopen(row)}
                        className="cursor-pointer gap-2"
                    >
                        <RotateCcw
                            className="h-4 w-4 text-primary"
                            aria-hidden="true"
                        />
                        <span>Reopen requirement</span>
                    </DropdownMenuItem>
                ) : null}

                {actions.canRepeat ? (
                    <DropdownMenuItem
                        onClick={() => onRepeat(row)}
                        className="cursor-pointer gap-2"
                    >
                        <Copy
                            className="h-4 w-4 text-primary"
                            aria-hidden="true"
                        />
                        <span>Repeat requirement</span>
                    </DropdownMenuItem>
                ) : null}

                {actions.canCancel ? (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onClick={() => onCancel(row)}
                            className="cursor-pointer gap-2 text-rose-700 focus:text-rose-700 dark:text-rose-400 dark:focus:text-rose-400"
                        >
                            <Ban className="h-4 w-4" aria-hidden="true" />
                            <span>Cancel requirement</span>
                        </DropdownMenuItem>
                    </>
                ) : null}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
