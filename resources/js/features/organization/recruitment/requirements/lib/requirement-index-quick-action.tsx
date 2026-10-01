import { CheckCircle2, Clock, Copy, PlayCircle, Send } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { RequirementIndexRow } from '@/types/recruitment';

export type RequirementIndexQuickActionHandlers = {
    onSubmit: (row: RequirementIndexRow) => void;
    onApprove: (row: RequirementIndexRow) => void;
    onResubmit: (row: RequirementIndexRow) => void;
    onResume: (row: RequirementIndexRow) => void;
    onFill: (row: RequirementIndexRow) => void;
    onExtend: (row: RequirementIndexRow) => void;
    onRepeat: (row: RequirementIndexRow) => void;
};

export function RequirementIndexQuickAction({
    row,
    handlers,
    disabled = false,
}: {
    row: RequirementIndexRow;
    handlers: RequirementIndexQuickActionHandlers;
    disabled?: boolean;
}) {
    if (row.next_action === 'submit' && row.can_submit) {
        return (
            <Button
                size="sm"
                variant="outline"
                disabled={disabled}
                onClick={() => handlers.onSubmit(row)}
                className="h-8 gap-1 border-primary/40 text-xs text-primary hover:bg-primary/10"
            >
                <Send className="h-3.5 w-3.5" aria-hidden="true" />
                Submit
            </Button>
        );
    }

    if (row.next_action === 'approve' && row.can_approve) {
        return (
            <Button
                size="sm"
                variant="outline"
                disabled={disabled}
                onClick={() => handlers.onApprove(row)}
                className="h-8 gap-1 border-emerald-500/40 text-xs text-emerald-700 hover:bg-emerald-500/10 dark:text-emerald-400"
            >
                <CheckCircle2 className="h-3.5 w-3.5" aria-hidden="true" />
                Approve
            </Button>
        );
    }

    if (row.next_action === 'resubmit' && row.can_resubmit) {
        return (
            <Button
                size="sm"
                variant="outline"
                disabled={disabled}
                onClick={() => handlers.onResubmit(row)}
                className="h-8 gap-1 border-primary/40 text-xs text-primary hover:bg-primary/10"
            >
                <Send className="h-3.5 w-3.5" aria-hidden="true" />
                Resubmit
            </Button>
        );
    }

    if (row.next_action === 'resume' && row.can_resume) {
        return (
            <Button
                size="sm"
                variant="outline"
                disabled={disabled}
                onClick={() => handlers.onResume(row)}
                className="h-8 gap-1 border-emerald-500/40 text-xs text-emerald-700 hover:bg-emerald-500/10 dark:text-emerald-400"
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
                disabled={disabled}
                onClick={() => handlers.onFill(row)}
                className="h-8 gap-1 border-sky-500/40 text-xs text-sky-700 hover:bg-sky-500/10 dark:text-sky-400"
            >
                <CheckCircle2 className="h-3.5 w-3.5" aria-hidden="true" />
                Mark filled
            </Button>
        );
    }

    if (row.next_action === 'extend' && row.can_extend) {
        return (
            <Button
                size="sm"
                variant="outline"
                disabled={disabled}
                onClick={() => handlers.onExtend(row)}
                className="h-8 gap-1 border-amber-500/40 text-xs text-amber-700 hover:bg-amber-500/10 dark:text-amber-400"
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
                disabled={disabled}
                onClick={() => handlers.onRepeat(row)}
                className="h-8 gap-1 border-primary/40 text-xs text-primary hover:bg-primary/10"
            >
                <Copy className="h-3.5 w-3.5" aria-hidden="true" />
                Repeat
            </Button>
        );
    }

    return null;
}
