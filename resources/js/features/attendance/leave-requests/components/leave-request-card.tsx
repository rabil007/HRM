import { FileText } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { formatDisplayDate } from '@/lib/format-date';
import { LeaveRequestRowActions } from './leave-request-row-actions';
import { LeaveRequestStatusBadge } from './leave-request-status-badge';
import type { LeaveRequest, LeaveRequestPermissions } from '../types';

export function LeaveRequestCard({
    leaveRequest,
    can,
    onEdit,
    onDelete,
    onApprove,
    onReject,
    onCancel,
}: {
    leaveRequest: LeaveRequest;
    can: LeaveRequestPermissions;
    onEdit: (leaveRequest: LeaveRequest) => void;
    onDelete: (leaveRequest: LeaveRequest) => void;
    onApprove: (leaveRequest: LeaveRequest) => void;
    onReject: (leaveRequest: LeaveRequest) => void;
    onCancel: (leaveRequest: LeaveRequest) => void;
}) {
    return (
        <Card className="glass-card group overflow-hidden relative transition-all duration-300 dark:bg-linear-to-br dark:from-white/6 dark:to-white/3 dark:hover:from-white/8 dark:hover:to-white/4">
            <CardHeader className="pb-3">
                <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                        <CardTitle className="text-lg font-extrabold tracking-tight line-clamp-1">
                            {leaveRequest.employee?.name ?? 'Unknown employee'}
                        </CardTitle>
                        <CardDescription className="mt-2 text-sm font-medium text-muted-foreground/85">
                            {formatDisplayDate(leaveRequest.start_date)} — {formatDisplayDate(leaveRequest.end_date)}
                        </CardDescription>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {leaveRequest.leave_type ? (
                                <Badge
                                    variant="secondary"
                                    className="bg-muted/40 text-muted-foreground border-border/60 text-[10px] uppercase font-bold tracking-wider dark:bg-white/5 dark:border-white/10"
                                >
                                    {leaveRequest.leave_type.code}
                                </Badge>
                            ) : null}
                            <Badge
                                variant="secondary"
                                className="bg-muted/40 text-muted-foreground border-border/60 text-[10px] uppercase font-bold tracking-wider dark:bg-white/5 dark:border-white/10"
                            >
                                {leaveRequest.total_days} days
                            </Badge>
                        </div>
                    </div>
                    <LeaveRequestStatusBadge status={leaveRequest.status} />
                </div>
            </CardHeader>

            <CardContent className="pt-0">
                <div className="grid gap-2 pb-16">
                    <div className="flex items-center justify-between gap-3 rounded-xl border border-border/60 bg-muted/30 px-3 py-2 dark:border-white/6 dark:bg-white/4">
                        <div className="text-xs font-semibold text-muted-foreground/80">Leave type</div>
                        <div className="text-sm font-bold truncate">{leaveRequest.leave_type?.name ?? '—'}</div>
                    </div>
                    {leaveRequest.reason ? (
                        <div className="rounded-xl border border-border/60 bg-muted/30 px-3 py-2 text-sm text-muted-foreground dark:border-white/6 dark:bg-white/4 line-clamp-2">
                            {leaveRequest.reason}
                        </div>
                    ) : null}
                    {leaveRequest.attachments[0] ? (
                        <a
                            href={leaveRequest.attachments[0].url}
                            className="flex items-center gap-2 rounded-xl border border-border/60 bg-muted/30 px-3 py-2 text-sm font-medium text-primary hover:underline dark:border-white/6 dark:bg-white/4"
                        >
                            <FileText className="size-4 shrink-0" />
                            <span className="truncate">{leaveRequest.attachments[0].name}</span>
                        </a>
                    ) : null}
                </div>
            </CardContent>

            <div className="pointer-events-none absolute bottom-4 left-4 right-4">
                <div className="pointer-events-auto">
                    <LeaveRequestRowActions
                        leaveRequest={leaveRequest}
                        can={can}
                        onEdit={onEdit}
                        onDelete={onDelete}
                        onApprove={onApprove}
                        onReject={onReject}
                        onCancel={onCancel}
                        wrapped
                    />
                </div>
            </div>
        </Card>
    );
}
