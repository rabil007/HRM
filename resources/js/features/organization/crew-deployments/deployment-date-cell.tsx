import type { ReactElement } from 'react';
import { cn } from '@/lib/utils';
import { formatIsoDateDisplay } from '@/pages/organization/_lib/format-iso-date-display';

export function DeploymentDateCell({
    value,
    field,
    overdueFields,
    dueSoonFields,
}: {
    value: string | null | undefined;
    field: string;
    overdueFields: string[];
    dueSoonFields: string[];
}): ReactElement {
    const isOverdue = overdueFields.includes(field);
    const isDueSoon = ! isOverdue && dueSoonFields.includes(field);

    return (
        <span
            className={cn(
                isOverdue && 'font-semibold text-red-500 dark:text-red-400',
                isDueSoon && 'font-semibold text-amber-500 dark:text-amber-400',
            )}
        >
            {formatIsoDateDisplay(value)}
        </span>
    );
}
