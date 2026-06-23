import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { PayrollPeriodStatus } from '../types';

const STATUS_STYLES: Record<PayrollPeriodStatus, string> = {
    draft: 'border-border/60 bg-muted/30 text-muted-foreground',
    processing: 'border-sky-500/30 bg-sky-500/10 text-sky-700 dark:text-sky-200',
    approved: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-200',
    paid: 'border-violet-500/30 bg-violet-500/10 text-violet-700 dark:text-violet-200',
    cancelled: 'border-red-500/30 bg-red-500/10 text-red-700 dark:text-red-200',
};

export function PayrollPeriodStatusBadge({
    status,
    label,
    className,
}: {
    status: PayrollPeriodStatus | string;
    label: string;
    className?: string;
}) {
    const style = STATUS_STYLES[status as PayrollPeriodStatus] ?? STATUS_STYLES.draft;

    return (
        <Badge variant="outline" className={cn(style, className)}>
            {label}
        </Badge>
    );
}
