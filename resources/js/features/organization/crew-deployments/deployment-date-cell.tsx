import type { ReactElement } from 'react';
import { cn } from '@/lib/utils';
import { formatIsoDateDisplay } from '@/pages/organization/_lib/format-iso-date-display';

export function DeploymentDateCell({
    value,
    field,
    overdueFields,
}: {
    value: string | null | undefined;
    field: string;
    overdueFields: string[];
}): ReactElement {
    const isOverdue = overdueFields.includes(field);

    return (
        <span
            className={cn(
                isOverdue && 'font-semibold text-red-500 dark:text-red-400',
            )}
        >
            {formatIsoDateDisplay(value)}
        </span>
    );
}
