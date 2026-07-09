import { Badge } from '@/components/ui/badge';

const LABELS: Record<string, string> = {
    awaiting_signature: 'Awaiting',
    submitted: 'Pending review',
    approved: 'Approved',
    rejected: 'Rejected',
    expired: 'Expired',
    cancelled: 'Cancelled',
};

const VARIANTS: Record<string, string> = {
    awaiting_signature: 'bg-amber-500/10 text-amber-700 dark:text-amber-400',
    submitted: 'bg-violet-500/10 text-violet-700 dark:text-violet-400',
    approved: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    rejected: 'bg-destructive/10 text-destructive',
    expired: 'bg-muted text-muted-foreground',
    cancelled: 'bg-muted text-muted-foreground',
};

export function SignatureStatusBadge({ status }: { status: string | null }) {
    if (!status) {
        return <span className="text-muted-foreground/70">—</span>;
    }

    return (
        <Badge
            variant="secondary"
            className={`border-0 ${VARIANTS[status] ?? 'bg-muted text-muted-foreground'}`}
        >
            {LABELS[status] ?? status}
        </Badge>
    );
}
