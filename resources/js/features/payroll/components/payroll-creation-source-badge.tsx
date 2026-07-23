import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

const SOURCE_STYLES: Record<
    'manual' | 'automatic',
    { label: string; className: string }
> = {
    automatic: {
        label: 'Created by system',
        className:
            'border-emerald-500/25 bg-emerald-500/10 text-emerald-700 dark:text-emerald-200 hover:bg-emerald-500/15',
    },
    manual: {
        label: 'Created by user',
        className:
            'border-slate-500/25 bg-slate-500/10 text-slate-700 dark:text-slate-200 hover:bg-slate-500/15',
    },
};

export function PayrollCreationSourceBadge({
    source,
    label,
    className,
}: {
    source: 'manual' | 'automatic';
    label?: string;
    className?: string;
}) {
    const style = SOURCE_STYLES[source];

    return (
        <Badge
            variant="outline"
            className={cn(
                'rounded-lg font-semibold',
                style.className,
                className,
            )}
        >
            {label ?? style.label}
        </Badge>
    );
}
