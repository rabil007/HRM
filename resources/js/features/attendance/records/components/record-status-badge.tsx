import { Badge } from '@/components/ui/badge';

const statusClass: Record<string, string> = {
    present: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    absent: 'bg-rose-500/15 text-rose-700 dark:text-rose-300',
    late: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    half_day: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    holiday: 'bg-violet-500/15 text-violet-700 dark:text-violet-300',
    weekend: 'bg-muted text-muted-foreground',
};

export function RecordStatusBadge({ status }: { status: string }) {
    return (
        <Badge variant="secondary" className={statusClass[status] ?? ''}>
            {status.replace('_', ' ')}
        </Badge>
    );
}
