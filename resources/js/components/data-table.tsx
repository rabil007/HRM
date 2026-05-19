import type { ReactElement, ReactNode } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableHead, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';

/** Shared table chrome for organization list views (shadcn Table inside glass card). */
export function OrganizationDataTable({
    children,
    minWidth = 'min-w-[980px]',
}: {
    children: ReactNode;
    minWidth?: string;
}): ReactElement {
    return (
        <Card className="glass-card w-full overflow-hidden">
            <CardContent className="min-h-[360px] w-full p-0">
                <Table className={minWidth}>{children}</Table>
            </CardContent>
        </Card>
    );
}

export function dataTableHeaderRowClass(): string {
    return 'border-b border-white/[0.08] bg-white/[0.03] hover:bg-white/[0.03]';
}

export function dataTableHeadClass(): string {
    return 'h-10 px-4 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground/80 whitespace-nowrap';
}

export function dataTableBodyRowClass(interactive = true): string {
    return cn(
        'border-b border-white/[0.05] transition-colors',
        interactive && 'cursor-pointer hover:bg-accent/40',
    );
}

export function dataTableCellClass(): string {
    return 'px-4 py-3.5 align-middle text-sm text-muted-foreground/80';
}

export function dataTableCellPrimaryClass(): string {
    return 'px-4 py-3.5 align-middle text-sm font-medium text-foreground';
}

export function dataTableActionsCellClass(): string {
    return 'px-4 py-3.5 align-middle text-right last:pr-5';
}

export function DataTableHeaderRow({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}): ReactElement {
    return <TableRow className={cn(dataTableHeaderRowClass(), className)}>{children}</TableRow>;
}

export function DataTableHead({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}): ReactElement {
    return <TableHead className={cn(dataTableHeadClass(), className)}>{children}</TableHead>;
}

/** Employee profile / records tables (native table element). */
export function recordsTableHeadRowClass(): string {
    return 'border-b border-white/[0.08] bg-white/[0.03] text-[11px] font-semibold uppercase tracking-wider text-zinc-500';
}

export function recordsTableThClass(): string {
    return 'px-5 py-3.5 font-medium first:pl-5 last:pr-5';
}

export function recordsTableRowClass(): string {
    return 'border-b border-white/[0.05] transition-colors last:border-0 hover:bg-white/[0.03]';
}

export function recordsTableTdClass(): string {
    return 'px-5 py-4 align-middle text-zinc-300 first:pl-5 last:pr-5';
}
