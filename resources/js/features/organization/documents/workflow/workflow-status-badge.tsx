import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

const STATUS_VARIANT: Record<string, string> = {
    pending: 'bg-amber-500/10 text-amber-700 dark:text-amber-300',
    approved: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    rejected: 'bg-red-500/10 text-red-700 dark:text-red-300',
    cancelled: 'bg-muted text-muted-foreground',
    active: 'bg-blue-500/10 text-blue-700 dark:text-blue-300',
    completed: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    skipped: 'bg-muted text-muted-foreground',
};

export function WorkflowStatusBadge({
    status,
    label,
    className,
}: {
    status: string;
    label: string;
    className?: string;
}) {
    return (
        <Badge
            variant="secondary"
            className={cn(
                'text-[10px] font-semibold tracking-wide uppercase',
                STATUS_VARIANT[status] ?? '',
                className,
            )}
        >
            {label}
        </Badge>
    );
}
