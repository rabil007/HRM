import type { ReactElement, ReactNode } from 'react';
import { recordsTableTdClass, recordsTableThClass } from '@/components/data-table';
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
        <div
            className={cn(
                'overflow-hidden rounded-2xl border border-white/[0.08] bg-gradient-to-b from-white/[0.06] to-white/[0.02] shadow-[inset_0_1px_0_0_rgba(255,255,255,0.06)]',
                className,
            )}
        >
            <div className="flex flex-col gap-4 border-b border-white/[0.06] px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex flex-wrap items-center gap-3">
                    <h3 className="text-sm font-semibold tracking-tight text-zinc-100">
                        {title}
                    </h3>
                    <span className="inline-flex items-center rounded-full border border-white/10 bg-white/[0.04] px-2.5 py-0.5 text-xs font-medium text-zinc-400">
                        {count} {countLabel}
                    </span>
                </div>
                {actions ? (
                    <div className="flex shrink-0 items-center gap-2">{actions}</div>
                ) : null}
            </div>

            {isEmpty ? (
                <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
                    <p className="max-w-sm text-sm text-zinc-500">{emptyMessage}</p>
                </div>
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
        <table className={cn('w-full min-w-[640px] text-left text-sm', className)}>
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
                'whitespace-nowrap text-right',
                className,
            )}
        >
            Actions
        </th>
    );
}

export function employeeRecordsActionsTdClass(className?: string): string {
    return cn(recordsTableTdClass(), 'whitespace-nowrap text-right', className);
}
