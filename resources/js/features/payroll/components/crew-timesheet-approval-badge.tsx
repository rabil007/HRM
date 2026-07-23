import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

const styles: Record<string, string> = {
    applied:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    approved:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300',
    submitted:
        'border-amber-500/30 bg-amber-500/10 text-amber-800 dark:text-amber-200',
    draft: 'border-border bg-muted/40 text-muted-foreground',
    returned: 'border-destructive/30 bg-destructive/10 text-destructive',
    not_applicable: 'border-border bg-muted/30 text-muted-foreground',
    not_entered: 'border-border bg-muted/30 text-muted-foreground',
};

export function CrewTimesheetApprovalBadge({
    status,
    label,
}: {
    status?: string | null;
    label?: string | null;
}) {
    if (!status) {
        return null;
    }

    return (
        <Badge
            variant="outline"
            className={cn('font-medium', styles[status] ?? styles.draft)}
        >
            {label ?? status}
        </Badge>
    );
}
