import { useHttp } from '@inertiajs/react';
import { Loader2, Mail } from 'lucide-react';
import { useEffect, useState } from 'react';
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
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { EmployeeProfileLink } from '@/features/organization/employees/components/employee-profile-link';
import { formatDisplayDateTime12h } from '@/lib/format-date';
import { toast } from '@/lib/toast';
import { cn } from '@/lib/utils';
import { sends as bulkEmailBatchSends } from '@/routes/organization/documents/bulk/email-batches';
import type {
    BulkEmailBatchDetail,
    BulkEmailBatchSend,
    BulkEmailBatchSendsResponse,
} from './types';

function sendStatusBadge(status: BulkEmailBatchSend['status']) {
    if (status === 'sent') {
        return (
            <Badge className="border-0 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                Sent
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

    return (
        <Badge className="border-0 bg-amber-500/10 text-amber-600 dark:text-amber-400">
            Skipped
        </Badge>
    );
}

function BatchSummaryStats({ batch }: { batch: BulkEmailBatchDetail }) {
    return (
        <div className="flex flex-wrap gap-2">
            <Badge
                variant="outline"
                className="border-emerald-500/25 bg-emerald-500/5 text-emerald-700 dark:text-emerald-400"
            >
                {batch.sent_count} sent
            </Badge>
            {batch.failed_count > 0 ? (
                <Badge
                    variant="outline"
                    className="border-destructive/25 bg-destructive/5 text-destructive"
                >
                    {batch.failed_count} failed
                </Badge>
            ) : null}
            {batch.skipped_no_email_count > 0 ? (
                <Badge
                    variant="outline"
                    className="border-amber-500/25 bg-amber-500/5 text-amber-700 dark:text-amber-400"
                >
                    {batch.skipped_no_email_count} skipped
                </Badge>
            ) : null}
            <Badge variant="outline" className="text-muted-foreground">
                {batch.total_selected} selected
            </Badge>
        </div>
    );
}

export function BulkEmailBatchSendsSheet({
    batchId,
    onOpenChange,
}: {
    batchId: number | null;
    onOpenChange: (open: boolean) => void;
}) {
    const open = batchId !== null;
    const [batch, setBatch] = useState<BulkEmailBatchDetail | null>(null);
    const [sends, setSends] = useState<BulkEmailBatchSend[]>([]);
    const [loading, setLoading] = useState(false);
    const http = useHttp();

    useEffect(() => {
        if (!open || batchId === null) {
            return;
        }

        let cancelled = false;
        setLoading(true);
        setBatch(null);
        setSends([]);

        http.get(bulkEmailBatchSends.url({ batch: batchId }))
            .then((data) => {
                if (cancelled) {
                    return;
                }

                const payload = data as BulkEmailBatchSendsResponse;
                setBatch(payload.batch);
                setSends(payload.sends);
            })
            .catch(() => {
                if (!cancelled) {
                    toast.error('Could not load email batch recipients.');
                }
            })
            .finally(() => {
                if (!cancelled) {
                    setLoading(false);
                }
            });

        return () => {
            cancelled = true;
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, batchId]);

    const sheetDescription = batch
        ? `${batch.document_type_label} · ${batch.sent_count} sent`
        : 'Recipients and delivery status for this email batch.';

    return (
        <Sheet
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    onOpenChange(false);
                }
            }}
        >
            <SheetContent
                side="right"
                className="flex h-full w-full flex-col gap-0 overflow-hidden p-0 sm:max-w-3xl"
            >
                <SheetHeader className="shrink-0 space-y-3 border-b border-border/60 px-6 py-5 pr-12">
                    <div className="flex items-center gap-2">
                        <div className="flex h-8 w-8 items-center justify-center rounded-lg border border-border/60 bg-muted/40">
                            <Mail className="h-4 w-4 text-muted-foreground" />
                        </div>
                        <div className="min-w-0 flex-1">
                            <SheetTitle className="text-base">
                                Email batch recipients
                            </SheetTitle>
                            <SheetDescription className="mt-0.5">
                                {sheetDescription}
                            </SheetDescription>
                        </div>
                    </div>

                    {batch ? (
                        <div className="space-y-3">
                            <div className="space-y-1">
                                <p className="text-sm font-semibold text-foreground">
                                    {batch.document_type_label}
                                </p>
                                <p
                                    className="line-clamp-2 text-sm text-muted-foreground"
                                    title={batch.subject}
                                >
                                    {batch.subject}
                                </p>
                                {batch.template_label ? (
                                    <p className="text-xs text-muted-foreground/70">
                                        Template: {batch.template_label}
                                    </p>
                                ) : null}
                            </div>

                            <BatchSummaryStats batch={batch} />

                            {batch.created_at ? (
                                <p className="text-xs text-muted-foreground/80">
                                    {formatDisplayDateTime12h(batch.created_at)}
                                    {batch.triggered_by
                                        ? ` · ${batch.triggered_by}`
                                        : ''}
                                </p>
                            ) : null}
                        </div>
                    ) : null}
                </SheetHeader>

                <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-6 py-4">
                    {loading ? (
                        <div className="flex items-center justify-center py-16 text-muted-foreground">
                            <Loader2 className="h-5 w-5 animate-spin" />
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <OrganizationDataTable
                                compact
                                minWidth="min-w-[720px]"
                            >
                                <TableHeader>
                                    <DataTableHeaderRow>
                                        <DataTableHead>Employee</DataTableHead>
                                        <DataTableHead>Recipient</DataTableHead>
                                        <DataTableHead>Status</DataTableHead>
                                        <DataTableHead>Sent at</DataTableHead>
                                        <DataTableHead>Details</DataTableHead>
                                    </DataTableHeaderRow>
                                </TableHeader>
                                <TableBody>
                                    {sends.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={5}
                                                className="p-0"
                                            >
                                                <EmptyState
                                                    title="No recipients recorded."
                                                    description="This batch has no email send rows yet."
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        sends.map((send) => (
                                            <TableRow
                                                key={send.id}
                                                className={dataTableBodyRowClass(
                                                    false,
                                                )}
                                            >
                                                <TableCell
                                                    className={dataTableCellPrimaryClass()}
                                                >
                                                    {send.employee.id ? (
                                                        <EmployeeProfileLink
                                                            employeeId={
                                                                send.employee.id
                                                            }
                                                            stopRowNavigation
                                                            className="truncate"
                                                        >
                                                            {send.employee
                                                                .name ?? '—'}
                                                        </EmployeeProfileLink>
                                                    ) : (
                                                        <span>—</span>
                                                    )}
                                                    {send.employee
                                                        .employee_no ? (
                                                        <div className="text-xs text-muted-foreground/70">
                                                            {
                                                                send.employee
                                                                    .employee_no
                                                            }
                                                        </div>
                                                    ) : null}
                                                </TableCell>
                                                <TableCell
                                                    className={dataTableCellClass()}
                                                >
                                                    {send.recipient_email ? (
                                                        <a
                                                            href={`mailto:${send.recipient_email}`}
                                                            className="text-sm text-primary hover:underline"
                                                        >
                                                            {
                                                                send.recipient_email
                                                            }
                                                        </a>
                                                    ) : (
                                                        <span className="text-muted-foreground/70">
                                                            —
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell
                                                    className={dataTableCellClass()}
                                                >
                                                    {sendStatusBadge(
                                                        send.status,
                                                    )}
                                                </TableCell>
                                                <TableCell
                                                    className={cn(
                                                        dataTableCellClass(),
                                                        'text-sm whitespace-nowrap',
                                                    )}
                                                >
                                                    {send.sent_at
                                                        ? formatDisplayDateTime12h(
                                                              send.sent_at,
                                                          )
                                                        : '—'}
                                                </TableCell>
                                                <TableCell
                                                    className={cn(
                                                        dataTableCellClass(),
                                                        'max-w-[200px]',
                                                    )}
                                                >
                                                    <span
                                                        className="line-clamp-2 text-sm text-muted-foreground/90"
                                                        title={
                                                            send.error ??
                                                            undefined
                                                        }
                                                    >
                                                        {send.error ?? '—'}
                                                    </span>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </OrganizationDataTable>
                        </div>
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}
