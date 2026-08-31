import { router } from '@inertiajs/react';
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
    TableBody,
    TableCell,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { WorkflowRequestListItem } from '@/features/organization/documents/workflow/types';
import { WorkflowStatusBadge } from '@/features/organization/documents/workflow/workflow-status-badge';
import { formatDisplayDateTime12h } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import documentRoutes from '@/routes/organization/documents';

export function WorkflowRequestsTable({
    requests,
}: {
    requests: WorkflowRequestListItem[];
}) {
    if (requests.length === 0) {
        return (
            <EmptyState
                title="You're all caught up"
                description="No approval requests need attention right now."
            />
        );
    }

    return (
        <OrganizationDataTable minWidth="min-w-[960px]">
            <TableHeader>
                <DataTableHeaderRow>
                    <DataTableHead>Employee</DataTableHead>
                    <DataTableHead>Document</DataTableHead>
                    <DataTableHead>Waiting for</DataTableHead>
                    <DataTableHead>Status</DataTableHead>
                    <DataTableHead>Requested</DataTableHead>
                </DataTableHeaderRow>
            </TableHeader>
            <TableBody>
                {requests.map((request) => {
                    const href = documentRoutes.requests.show.url({
                        workflowRequest: request.id,
                    });

                    return (
                        <TableRow
                            key={request.id}
                            className={cn(
                                dataTableBodyRowClass(false),
                                'cursor-pointer',
                            )}
                            onClick={() => router.visit(href)}
                        >
                            <TableCell className={dataTableCellPrimaryClass()}>
                                <div>{request.employee.name ?? '—'}</div>
                                <div className="text-xs text-muted-foreground">
                                    {request.employee.employee_no ?? ''}
                                </div>
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                <div className="font-medium">
                                    {request.document.title ?? 'Document'}
                                </div>
                                {request.current_stage ? (
                                    <div className="text-xs text-muted-foreground">
                                        {request.current_stage.action_label}
                                    </div>
                                ) : null}
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                {request.waiting_for || '—'}
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                <WorkflowStatusBadge
                                    status={request.status}
                                    label={request.human_status}
                                />
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                {request.requested_at
                                    ? formatDisplayDateTime12h(
                                          request.requested_at,
                                      )
                                    : '—'}
                            </TableCell>
                        </TableRow>
                    );
                })}
            </TableBody>
        </OrganizationDataTable>
    );
}
