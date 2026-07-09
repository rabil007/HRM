import type { ReactElement, ReactNode } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableHead, TableRow } from '@/components/ui/table';
import { tables } from '@/lib/design-system';
import { cn } from '@/lib/utils';

/** Shared table chrome for organization list views (shadcn Table inside glass card). */
export function OrganizationDataTable({
    children,
    minWidth = 'min-w-[980px]',
    compact = false,
    header,
    tableClassName,
}: {
    children: ReactNode;
    minWidth?: string;
    compact?: boolean;
    header?: ReactNode;
    tableClassName?: string;
}): ReactElement {
    return (
        <Card
            className={cn(
                'w-full overflow-hidden glass-card',
                header && 'gap-0 py-0',
            )}
        >
            {header ? (
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 px-4 py-3">
                    {header}
                </div>
            ) : null}
            <CardContent
                className={cn(
                    'w-full p-0',
                    compact ? 'min-h-0' : 'min-h-[360px]',
                )}
            >
                <Table className={cn(minWidth, tableClassName)}>{children}</Table>
            </CardContent>
        </Card>
    );
}

export function dataTableHeaderRowClass(): string {
    return tables.headRowLegacy;
}

export function dataTableHeadClass(): string {
    return tables.headCell;
}

export function dataTableBodyRowClass(interactive = true): string {
    return cn(tables.bodyRow, !interactive && 'hover:bg-transparent');
}

export function dataTableCellClass(): string {
    return tables.cell;
}

export function dataTableCellPrimaryClass(): string {
    return tables.cellPrimary;
}

export function dataTableActionsCellClass(): string {
    return tables.actionsCell;
}

export function DataTableHeaderRow({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}): ReactElement {
    return (
        <TableRow className={cn(dataTableHeaderRowClass(), className)}>
            {children}
        </TableRow>
    );
}

export function DataTableHead({
    children,
    className,
    colSpan,
    rowSpan,
}: {
    children: ReactNode;
    className?: string;
    colSpan?: number;
    rowSpan?: number;
}): ReactElement {
    return (
        <TableHead
            colSpan={colSpan}
            rowSpan={rowSpan}
            className={cn(dataTableHeadClass(), className)}
        >
            {children}
        </TableHead>
    );
}

/** Employee profile / records tables (native table element). */
export function recordsTableHeadRowClass(): string {
    return tables.headRow;
}

export function recordsTableThClass(): string {
    return tables.th;
}

export function recordsTableRowClass(): string {
    return tables.row;
}

export function recordsTableTdClass(): string {
    return tables.td;
}
