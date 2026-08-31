import { router } from '@inertiajs/react';
import { ArrowRight, User } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { WorkflowRequestListItem } from '@/features/organization/documents/workflow/types';
import { WorkflowStatusBadge } from '@/features/organization/documents/workflow/workflow-status-badge';
import { formatDisplayDateTime12h } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import documentRoutes from '@/routes/organization/documents';

/** Returns a deterministic background colour class from a name string. */
function avatarColour(name: string | null): string {
    const colours = [
        'bg-blue-500',
        'bg-violet-500',
        'bg-emerald-500',
        'bg-amber-500',
        'bg-rose-500',
        'bg-cyan-500',
        'bg-fuchsia-500',
        'bg-teal-500',
    ];
    const seed = (name ?? '').charCodeAt(0) || 0;

    return colours[seed % colours.length];
}

const ACTION_COLOURS: Record<string, string> = {
    review: 'bg-blue-500/10 text-blue-700 dark:text-blue-300',
    approve: 'bg-violet-500/10 text-violet-700 dark:text-violet-300',
};

export function WorkflowRequestsTable({
    requests,
}: {
    requests: WorkflowRequestListItem[];
}) {
    if (requests.length === 0) {
        return (
            <EmptyState
                icon={
                    <User className="mx-auto mb-2 h-8 w-8 text-muted-foreground/50" />
                }
                title="You're all caught up"
                description="No approval requests need attention right now."
            />
        );
    }

    return (
        <div className="overflow-hidden rounded-xl border border-border/80 bg-card shadow-xs">
            <Table>
                <TableHeader>
                    <TableRow className="hover:bg-transparent">
                        <TableHead className="w-[22%]">Employee</TableHead>
                        <TableHead className="w-[30%]">Document</TableHead>
                        <TableHead className="w-[20%]">Waiting for</TableHead>
                        <TableHead className="w-[14%]">Status</TableHead>
                        <TableHead className="w-[14%]">Requested</TableHead>
                        <TableHead className="w-[1%]" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {requests.map((request) => {
                        const href = documentRoutes.requests.show.url({
                            workflowRequest: request.id,
                        });
                        const initial = (request.employee.name ?? '?')
                            .charAt(0)
                            .toUpperCase();
                        const colour = avatarColour(request.employee.name);
                        const actionKey = request.current_stage?.action ?? '';

                        return (
                            <TableRow
                                key={request.id}
                                className="group cursor-pointer transition-colors hover:bg-muted/40"
                                onClick={() => router.visit(href)}
                            >
                                {/* Employee */}
                                <TableCell>
                                    <div className="flex items-center gap-3">
                                        <div
                                            className={cn(
                                                'flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold text-white',
                                                colour,
                                            )}
                                        >
                                            {initial}
                                        </div>
                                        <div>
                                            <div className="font-medium text-foreground">
                                                {request.employee.name ?? '—'}
                                            </div>
                                            {request.employee.employee_no && (
                                                <div className="font-mono text-[11px] text-muted-foreground">
                                                    {
                                                        request.employee
                                                            .employee_no
                                                    }
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                </TableCell>

                                {/* Document + stage action */}
                                <TableCell>
                                    <div className="font-medium text-foreground">
                                        {request.document.title ?? 'Document'}
                                    </div>
                                    {request.current_stage && (
                                        <span
                                            className={cn(
                                                'mt-0.5 inline-block rounded px-1.5 py-0.5 text-[10px] font-semibold tracking-wide uppercase',
                                                ACTION_COLOURS[actionKey] ??
                                                    'bg-muted text-muted-foreground',
                                            )}
                                        >
                                            {request.current_stage.action_label}
                                        </span>
                                    )}
                                </TableCell>

                                {/* Waiting for */}
                                <TableCell className="text-sm text-muted-foreground">
                                    {request.waiting_for || '—'}
                                </TableCell>

                                {/* Status */}
                                <TableCell>
                                    <WorkflowStatusBadge
                                        status={request.status}
                                        label={request.human_status}
                                    />
                                </TableCell>

                                {/* Requested at */}
                                <TableCell className="text-sm text-muted-foreground">
                                    {request.requested_at
                                        ? formatDisplayDateTime12h(
                                              request.requested_at,
                                          )
                                        : '—'}
                                </TableCell>

                                {/* Row arrow */}
                                <TableCell className="pr-4 text-right">
                                    <ArrowRight className="ml-auto h-4 w-4 text-muted-foreground/30 transition-all group-hover:translate-x-0.5 group-hover:text-muted-foreground" />
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}
