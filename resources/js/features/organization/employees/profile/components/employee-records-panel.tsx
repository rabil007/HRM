import type { ReactElement, ReactNode } from 'react';
import {
    recordsTableTdClass,
    recordsTableThClass,
} from '@/components/data-table';
import { EmptyState } from '@/components/design-system/empty-state';
import { surfaces, typography } from '@/lib/design-system';
import { cn } from '@/lib/utils';

export type EmployeeRecordsPanelProps = {
    title: string;
    count: number;
    countLabel?: string;
    actions?: ReactNode;
    emptyMessage?: string;
    isEmpty?: boolean;
    children: ReactNode;
    className?: string;
};

export function EmployeeRecordsPanel({
    title,
    count,
    countLabel = 'total',
    actions,
    emptyMessage = 'No records yet.',
    isEmpty = false,
    children,
    className,
}: EmployeeRecordsPanelProps): ReactElement {
    return (
        <div className={cn(surfaces.panel, className)}>
            <div className={surfaces.panelHeader}>
                <div className="flex flex-wrap items-center gap-3">
                    <h3 className={surfaces.panelTitle}>{title}</h3>
                    <span className={surfaces.panelBadge}>
                        {count} {countLabel}
                    </span>
                </div>
                {actions ? (
                    <div className="flex shrink-0 items-center gap-2">
                        {actions}
                    </div>
                ) : null}
            </div>

            {isEmpty ? (
                <EmptyState title="No records" description={emptyMessage} />
            ) : (
                <div className="overflow-x-auto">{children}</div>
            )}
        </div>
    );
}

export type EmployeeRecordsTableProps = {
    children: ReactNode;
    className?: string;
};

export function EmployeeRecordsTable({
    children,
    className,
}: EmployeeRecordsTableProps): ReactElement {
    return (
        <table
            className={cn('w-full min-w-[640px] text-left text-sm', className)}
        >
            {children}
        </table>
    );
}

export {
    recordsTableHeadRowClass as employeeRecordsTableHeadClass,
    recordsTableRowClass as employeeRecordsTableRowClass,
    recordsTableTdClass as employeeRecordsTableTdClass,
    recordsTableThClass as employeeRecordsTableThClass,
} from '@/components/data-table';

export function EmployeeRecordsActionsHeader({
    className,
}: {
    className?: string;
} = {}): ReactElement {
    return (
        <th
            className={cn(
                recordsTableThClass(),
                'text-right whitespace-nowrap',
                className,
            )}
        >
            <span className={typography.label}>Actions</span>
        </th>
    );
}

export function employeeRecordsActionsTdClass(className?: string): string {
    return cn(recordsTableTdClass(), 'text-right whitespace-nowrap', className);
}
