import type { ReactNode } from 'react';
import {
    OrganizationDataTable,
    DataTableHead,
    DataTableHeaderRow,
    dataTableBodyRowClass,
    dataTableCellClass,
    dataTableCellPrimaryClass,
} from '@/components/data-table';
import { EmptyState } from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDisplayDateTime12h } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import type { BulkActivityItem } from './types';

function activitySummary(item: BulkActivityItem): string {
    if (item.kind === 'generation') {
        const parts = [`${item.generated_count} created`];

        if (item.replaced_count > 0) {
            parts.push(`${item.replaced_count} updated`);
        }

        if (item.skipped_count > 0) {
            parts.push(`${item.skipped_count} skipped`);
        }

        if (item.failed_count > 0) {
            parts.push(`${item.failed_count} failed`);
        }

        return parts.join(' · ');
    }

    const parts = [`${item.sent_count} sent`];

    if (item.skipped_no_email_count > 0) {
        parts.push(`${item.skipped_no_email_count} skipped (no email)`);
    }

    if (item.failed_count > 0) {
        parts.push(`${item.failed_count} failed`);
    }

    if (item.template_label) {
        parts.push(`Template: ${item.template_label}`);
    }

    return parts.join(' · ');
}

function generationStatusBadge(status: string) {
    if (status === 'completed') {
        return (
            <Badge className="border-0 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                Completed
            </Badge>
        );
    }

    if (status === 'failed') {
        return (
            <Badge className="border-0 bg-destructive/10 text-destructive">
                Failed
            </Badge>
        );
    }

    if (status === 'running' || status === 'queued') {
        return (
            <Badge className="border-0 bg-amber-500/10 text-amber-600 dark:text-amber-400">
                {status === 'queued' ? 'Queued' : 'Running'}
            </Badge>
        );
    }

    return <Badge variant="outline">{status}</Badge>;
}

export function BulkDocumentsHistoryTable({
    activity,
    header,
}: {
    activity: BulkActivityItem[];
    header?: ReactNode;
}) {
    return (
        <OrganizationDataTable minWidth="min-w-[920px]" header={header}>
            <TableHeader>
                <DataTableHeaderRow>
                    <DataTableHead>Date</DataTableHead>
                    <DataTableHead>Action</DataTableHead>
                    <DataTableHead>Summary</DataTableHead>
                    <DataTableHead>Triggered by</DataTableHead>
                    <DataTableHead>Status</DataTableHead>
                </DataTableHeaderRow>
            </TableHeader>
            <TableBody>
                {activity.length === 0 ? (
                    <TableRow>
                        <TableCell colSpan={5} className="p-0">
                            <EmptyState
                                title="No activity yet."
                                description="Bulk generation and email runs will appear here."
                            />
                        </TableCell>
                    </TableRow>
                ) : (
                    activity.map((item) => {
                        const isGeneration = item.kind === 'generation';

                        return (
                            <TableRow
                                key={`${item.kind}-${item.id}`}
                                className={dataTableBodyRowClass(false)}
                            >
                                <TableCell
                                    className={cn(
                                        dataTableCellClass(),
                                        'whitespace-nowrap text-sm',
                                    )}
                                >
                                    {item.created_at
                                        ? formatDisplayDateTime12h(item.created_at)
                                        : '—'}
                                </TableCell>
                                <TableCell className={dataTableCellPrimaryClass()}>
                                    <div>{item.document_type_label}</div>
                                    <Badge
                                        variant="outline"
                                        className={cn(
                                            'mt-1 h-5 border-0 px-1.5 text-[10px] font-semibold uppercase tracking-wide',
                                            isGeneration
                                                ? 'bg-primary/10 text-primary'
                                                : 'bg-sky-500/10 text-sky-500',
                                        )}
                                    >
                                        {isGeneration ? 'Generated' : 'Emailed'}
                                    </Badge>
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    <span className="text-sm text-muted-foreground/90">
                                        {activitySummary(item)}
                                    </span>
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {item.triggered_by ?? '—'}
                                </TableCell>
                                <TableCell className={dataTableCellClass()}>
                                    {isGeneration ? (
                                        generationStatusBadge(item.status)
                                    ) : (
                                        <Badge
                                            variant="outline"
                                            className="border-0 bg-sky-500/10 text-sky-500"
                                        >
                                            Sent
                                        </Badge>
                                    )}
                                </TableCell>
                            </TableRow>
                        );
                    })
                )}
            </TableBody>
        </OrganizationDataTable>
    );
}
