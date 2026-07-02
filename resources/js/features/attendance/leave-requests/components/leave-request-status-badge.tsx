import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { LeaveRequestStatus } from '../types';

const STATUS_STYLES: Record<LeaveRequestStatus, string> = {
    pending:
        'bg-amber-500/10 text-amber-700 border-amber-500/20 dark:text-amber-200',
    approved:
        'bg-emerald-500/10 text-emerald-700 border-emerald-500/20 dark:text-emerald-200',
    rejected: 'bg-red-500/10 text-red-700 border-red-500/20 dark:text-red-200',
    cancelled:
        'bg-muted/60 text-muted-foreground border-border dark:bg-zinc-500/10 dark:text-zinc-200 dark:border-zinc-500/20',
};

const STATUS_LABELS: Record<LeaveRequestStatus, string> = {
    pending: 'Pending',
    approved: 'Approved',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};

export function LeaveRequestStatusBadge({
    status,
}: {
    status: LeaveRequestStatus;
}) {
    return (
        <Badge
            className={cn(
                'border text-[10px] font-bold tracking-wider uppercase',
                STATUS_STYLES[status],
            )}
        >
            {STATUS_LABELS[status]}
        </Badge>
    );
}
