import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { PayrollCategory } from '../types';

const CATEGORY_STYLES: Record<
    PayrollCategory,
    { label: string; className: string }
> = {
    crew: {
        label: 'Crew',
        className:
            'border-sky-500/25 bg-sky-500/10 text-sky-700 dark:text-sky-200 hover:bg-sky-500/15',
    },
    office: {
        label: 'Office',
        className:
            'border-violet-500/25 bg-violet-500/10 text-violet-700 dark:text-violet-200 hover:bg-violet-500/15',
    },
};

export function PayrollCategoryBadge({
    category,
    label,
    className,
}: {
    category: PayrollCategory;
    label?: string;
    className?: string;
}) {
    const style = CATEGORY_STYLES[category];

    return (
        <Badge variant="outline" className={cn('rounded-lg font-semibold', style.className, className)}>
            {label ?? style.label}
        </Badge>
    );
}
