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
                title="No review or approval requests"
                description="Generated documents with an active workflow will appear here."
            />
        );
    }

    return (
        <OrganizationDataTable minWidth="min-w-[960px]">
            <TableHeader>
                <DataTableHeaderRow>
                    <DataTableHead>Document</DataTableHead>
                    <DataTableHead>Employee</DataTableHead>
                    <DataTableHead>Requested by</DataTableHead>
                    <DataTableHead>Current stage</DataTableHead>
                    <DataTableHead>Assigned to</DataTableHead>
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
                                {request.document.title ?? 'Document'}
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                <div>{request.employee.name ?? '—'}</div>
                                <div className="text-xs text-muted-foreground">
                                    {request.employee.employee_no ?? ''}
                                </div>
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                {request.requested_by.name}
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                {request.current_stage
                                    ? `${request.current_stage.action_label} (${request.current_stage.sequence})`
                                    : '—'}
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                {request.assigned_to.length > 0
                                    ? request.assigned_to.join(', ')
                                    : '—'}
                            </TableCell>
                            <TableCell className={dataTableCellClass()}>
                                <WorkflowStatusBadge
                                    status={request.status}
                                    label={request.status_label}
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
