import { ArrowUpRight, FileText, Mail } from 'lucide-react';
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
import {
    MobileRecordCard,
    MobileRecordList,
} from '@/components/mobile-record-list';
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

function formatActivityResult(item: BulkActivityItem): string {
    if (item.kind === 'generation') {
        const parts: string[] = [];

        if (
            item.generated_count > 0 ||
            (item.replaced_count === 0 &&
                item.skipped_count === 0 &&
                item.failed_count === 0)
        ) {
            parts.push(`${item.generated_count} created`);
        }

        if (item.replaced_count > 0) {
            parts.push(`${item.replaced_count} replaced`);
        }

        if (item.skipped_count > 0) {
            parts.push(`${item.skipped_count} skipped`);
        }

        if (item.failed_count > 0) {
            parts.push(`${item.failed_count} failed`);
        }

        return parts.join(' · ');
    }

    const parts: string[] = [`${item.sent_count} sent`];

    if (item.skipped_no_email_count > 0) {
        parts.push(`${item.skipped_no_email_count} no email`);
    }

    if (item.failed_count > 0) {
        parts.push(`${item.failed_count} failed`);
    }

    if (item.template_label) {
        parts.push(`Template: ${item.template_label}`);
    }

    return parts.join(' · ');
}

function getActivityStatus(item: BulkActivityItem): {
    label: string;
    variant: 'emerald' | 'amber' | 'destructive' | 'secondary';
} {
    if (item.kind === 'generation') {
        if (item.status === 'failed') {
            return { label: 'Failed', variant: 'destructive' };
        }

        if (item.status === 'running') {
            return { label: 'Running', variant: 'amber' };
        }

        if (item.status === 'queued') {
            return { label: 'Queued', variant: 'secondary' };
        }

        if (item.status === 'completed') {
            if (item.failed_count > 0 || item.skipped_count > 0) {
                return { label: 'Completed with issues', variant: 'amber' };
            }

            return { label: 'Completed', variant: 'emerald' };
        }

        return { label: item.status, variant: 'secondary' };
    }

    if (item.failed_count > 0 || item.skipped_no_email_count > 0) {
        if (item.sent_count === 0 && item.failed_count > 0) {
            return { label: 'Failed', variant: 'destructive' };
        }

        return { label: 'Completed with issues', variant: 'amber' };
    }

    return { label: 'Completed', variant: 'emerald' };
}

function ActivityStatusBadge({ item }: { item: BulkActivityItem }) {
    const status = getActivityStatus(item);

    if (status.variant === 'emerald') {
        return (
            <Badge className="border-0 bg-emerald-500/10 font-medium text-emerald-600 dark:text-emerald-400">
                {status.label}
            </Badge>
        );
    }

    if (status.variant === 'destructive') {
        return (
            <Badge className="border-0 bg-destructive/10 font-medium text-destructive">
                {status.label}
            </Badge>
        );
    }

    if (status.variant === 'amber') {
        return (
            <Badge className="border-0 bg-amber-500/10 font-medium text-amber-600 dark:text-amber-400">
                {status.label}
            </Badge>
        );
    }

    return (
        <Badge variant="outline" className="font-medium text-muted-foreground">
            {status.label}
        </Badge>
    );
}

function getOperationTitle(item: BulkActivityItem): string {
    if (item.kind === 'generation') {
        if (item.generated_count === 0 && item.replaced_count > 0) {
            return `Regenerated ${item.document_type_label}`;
        }

        return `Generated ${item.document_type_label}`;
    }

    return `Sent ${item.document_type_label}`;
}

export function BulkDocumentsHistoryTable({
    activity,
    header,
    onEmailBatchClick,
}: {
    activity: BulkActivityItem[];
    header?: ReactNode;
    onEmailBatchClick?: (batchId: number) => void;
}) {
    return (
        <div className="space-y-4">
            {/* Desktop table view */}
            <div className="hidden md:block">
                <OrganizationDataTable minWidth="min-w-[920px]" header={header}>
                    <TableHeader>
                        <DataTableHeaderRow>
                            <DataTableHead>Operation</DataTableHead>
                            <DataTableHead>Result</DataTableHead>
                            <DataTableHead>Triggered By</DataTableHead>
                            <DataTableHead>Date</DataTableHead>
                            <DataTableHead className="text-right">
                                Status
                            </DataTableHead>
                        </DataTableHeaderRow>
                    </TableHeader>
                    <TableBody>
                        {activity.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={5} className="p-0">
                                    <EmptyState
                                        title="No activity yet."
                                        description="Document generation and email history will appear here."
                                    />
                                </TableCell>
                            </TableRow>
                        ) : (
                            activity.map((item) => {
                                const isGeneration = item.kind === 'generation';
                                const isClickable =
                                    !isGeneration &&
                                    onEmailBatchClick !== undefined;
                                const operationTitle = getOperationTitle(item);

                                return (
                                    <TableRow
                                        key={`${item.kind}-${item.id}`}
                                        className={cn(
                                            dataTableBodyRowClass(false),
                                            isClickable &&
                                                'cursor-pointer hover:bg-muted/40',
                                        )}
                                        onClick={
                                            isClickable
                                                ? () =>
                                                      onEmailBatchClick(item.id)
                                                : undefined
                                        }
                                    >
                                        <TableCell
                                            className={dataTableCellPrimaryClass()}
                                        >
                                            <div className="flex items-center gap-2.5">
                                                <div
                                                    className={cn(
                                                        'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                                                        isGeneration
                                                            ? 'bg-primary/10 text-primary'
                                                            : 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
                                                    )}
                                                >
                                                    {isGeneration ? (
                                                        <FileText className="h-4 w-4" />
                                                    ) : (
                                                        <Mail className="h-4 w-4" />
                                                    )}
                                                </div>
                                                <div>
                                                    <div className="flex items-center gap-1.5 font-medium text-foreground">
                                                        <span>
                                                            {operationTitle}
                                                        </span>
                                                        {isClickable ? (
                                                            <ArrowUpRight className="h-3.5 w-3.5 text-muted-foreground/60 transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
                                                        ) : null}
                                                    </div>
                                                    <Badge
                                                        variant="outline"
                                                        className={cn(
                                                            'mt-1 h-4.5 border-0 px-1.5 text-[10px] font-semibold tracking-wide uppercase',
                                                            isGeneration
                                                                ? 'bg-primary/10 text-primary'
                                                                : 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
                                                        )}
                                                    >
                                                        {isGeneration
                                                            ? 'Generation'
                                                            : 'Email Delivery'}
                                                    </Badge>
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            <span className="text-sm text-muted-foreground/90">
                                                {formatActivityResult(item)}
                                            </span>
                                        </TableCell>
                                        <TableCell
                                            className={dataTableCellClass()}
                                        >
                                            <span className="text-sm text-foreground">
                                                {item.triggered_by || 'System'}
                                            </span>
                                        </TableCell>
                                        <TableCell
                                            className={cn(
                                                dataTableCellClass(),
                                                'text-sm whitespace-nowrap text-muted-foreground',
                                            )}
                                        >
                                            {item.created_at
                                                ? formatDisplayDateTime12h(
                                                      item.created_at,
                                                  )
                                                : '—'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <ActivityStatusBadge item={item} />
                                        </TableCell>
                                    </TableRow>
                                );
                            })
                        )}
                    </TableBody>
                </OrganizationDataTable>
            </div>

            {/* Mobile record list view */}
            <div className="md:hidden">
                {header ? (
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-3 border-b border-border/60 pb-3">
                        {header}
                    </div>
                ) : null}

                {activity.length === 0 ? (
                    <EmptyState
                        title="No activity yet."
                        description="Document generation and email history will appear here."
                    />
                ) : (
                    <MobileRecordList labelledBy="activity-records-list">
                        {activity.map((item) => {
                            const isGeneration = item.kind === 'generation';
                            const isClickable =
                                !isGeneration &&
                                onEmailBatchClick !== undefined;
                            const operationTitle = getOperationTitle(item);

                            return (
                                <MobileRecordCard
                                    key={`mobile-${item.kind}-${item.id}`}
                                    leading={
                                        <div
                                            className={cn(
                                                'mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg',
                                                isGeneration
                                                    ? 'bg-primary/10 text-primary'
                                                    : 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
                                            )}
                                        >
                                            {isGeneration ? (
                                                <FileText className="h-4 w-4" />
                                            ) : (
                                                <Mail className="h-4 w-4" />
                                            )}
                                        </div>
                                    }
                                    title={operationTitle}
                                    subtitle={
                                        item.created_at
                                            ? formatDisplayDateTime12h(
                                                  item.created_at,
                                              )
                                            : undefined
                                    }
                                    meta={[
                                        formatActivityResult(item),
                                        `By ${item.triggered_by || 'System'}`,
                                    ]}
                                    status={<ActivityStatusBadge item={item} />}
                                    primaryAction={
                                        isClickable
                                            ? {
                                                  label: 'View details',
                                                  onClick: () =>
                                                      onEmailBatchClick(
                                                          item.id,
                                                      ),
                                              }
                                            : undefined
                                    }
                                />
                            );
                        })}
                    </MobileRecordList>
                )}
            </div>
        </div>
    );
}
