import { Head, router, useForm } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { RecentActivityCard } from '@/components/recent-activity-card';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { LeaveRequestDeleteDialog } from '@/features/attendance/leave-requests/components/leave-request-delete-dialog';
import { LeaveRequestFormSheet } from '@/features/attendance/leave-requests/components/leave-request-form-sheet';
import { LeaveRequestRejectDialog } from '@/features/attendance/leave-requests/components/leave-request-reject-dialog';
import { LeaveRequestRowActions } from '@/features/attendance/leave-requests/components/leave-request-row-actions';
import { LeaveRequestStatusBadge } from '@/features/attendance/leave-requests/components/leave-request-status-badge';
import {
    leaveRequestToFormData,
    type LeaveRequest,
    type LeaveRequestEmployeeOption,
    type LeaveRequestPermissions,
    type LeaveRequestTypeOption,
} from '@/features/attendance/leave-requests/types';
import { formatDisplayDate } from '@/lib/format-date';
import { toast } from '@/lib/toast';

function Field({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-center justify-between gap-3 px-6 py-4">
            <div className="text-sm font-semibold text-muted-foreground/80">{label}</div>
            <div className="text-right text-sm font-bold">{value}</div>
        </div>
    );
}

export default function LeaveRequestDetails({
    leave_request,
    employees,
    leave_types,
    recent_activity,
    can_view_audit,
    can,
}: {
    leave_request: LeaveRequest;
    employees: LeaveRequestEmployeeOption[];
    leave_types: LeaveRequestTypeOption[];
    recent_activity: RecentActivityItem[];
    can_view_audit: boolean;
    can: LeaveRequestPermissions;
}) {
    const [editOpen, setEditOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [isRejectOpen, setIsRejectOpen] = useState(false);
    const form = useForm(leaveRequestToFormData(leave_request));

    const canModify = leave_request.status === 'pending' && can.update;

    const submit = () => {
        if (!form.data.employee_id) {
            form.setError('employee_id', 'Employee is required.');

            return;
        }

        if (!form.data.leave_type_id) {
            form.setError('leave_type_id', 'Leave type is required.');

            return;
        }

        form.put(`/attendance/leave-requests/${leave_request.id}`, {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => setEditOpen(false),
        });
    };

    const approve = () => {
        router.put(`/attendance/leave-requests/${leave_request.id}/approve`, {}, {
            preserveScroll: true,
            onError: () => toast.error('Failed to approve leave request. Please try again.'),
        });
    };

    const reject = () => {
        setIsRejectOpen(true);
    };

    const cancel = () => {
        router.put(`/attendance/leave-requests/${leave_request.id}/cancel`, {}, {
            preserveScroll: true,
            onError: () => toast.error('Failed to cancel leave request. Please try again.'),
        });
    };

    return (
        <Main>
            <Head title={`Leave request • ${leave_request.employee?.name ?? 'Unknown'}`} />

            <DetailsHeader
                kicker="Attendance"
                title={leave_request.employee?.name ?? 'Leave request'}
                description={`${leave_request.leave_type?.name ?? '—'} • ${formatDisplayDate(leave_request.start_date)} — ${formatDisplayDate(leave_request.end_date)}`}
                backHref="/attendance/leave-requests"
                backLabel="Back to leave requests"
                actions={
                    <LeaveRequestRowActions
                        leaveRequest={leave_request}
                        can={can}
                        onEdit={() => setEditOpen(true)}
                        onDelete={() => setIsDeleteOpen(true)}
                        onApprove={approve}
                        onReject={reject}
                        onCancel={cancel}
                        wrapped
                    />
                }
            />

            <div className="grid gap-6 lg:grid-cols-2">
                <Card className="glass-card border-border bg-card dark:border-white/5 dark:bg-white/5">
                    <CardHeader className="flex flex-row items-start justify-between gap-4">
                        <div className="space-y-1">
                            <CardTitle className="text-xl font-bold tracking-tight">Request details</CardTitle>
                            <div className="text-sm text-muted-foreground/80">Employee leave request information.</div>
                        </div>
                        <LeaveRequestStatusBadge status={leave_request.status} />
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="divide-y divide-border dark:divide-white/5">
                            <Field label="Employee" value={leave_request.employee?.name ?? '—'} />
                            <Field label="Employee no." value={leave_request.employee?.employee_no ?? '—'} />
                            <Field label="Leave type" value={leave_request.leave_type?.name ?? '—'} />
                            <Field label="Start date" value={formatDisplayDate(leave_request.start_date)} />
                            <Field label="End date" value={formatDisplayDate(leave_request.end_date)} />
                            <Field label="Total days" value={String(leave_request.total_days)} />
                            <Field label="Reason" value={leave_request.reason?.trim() ? leave_request.reason : '—'} />
                        </div>
                    </CardContent>
                </Card>

                <Card className="glass-card border-border bg-card dark:border-white/5 dark:bg-white/5">
                    <CardHeader>
                        <CardTitle className="text-xl font-bold tracking-tight">Decision</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-0 p-0">
                        <div className="divide-y divide-border dark:divide-white/5">
                            <Field label="Approver" value={leave_request.approver?.name ?? '—'} />
                            <Field label="Decided at" value={leave_request.decided_at ? formatDisplayDate(leave_request.decided_at) : '—'} />
                            <Field
                                label="Rejection reason"
                                value={leave_request.rejection_reason?.trim() ? leave_request.rejection_reason : '—'}
                            />
                            <Field label="Created" value={leave_request.created_at ? formatDisplayDate(leave_request.created_at) : '—'} />
                        </div>

                        {leave_request.attachments[0] ? (
                            <div className="border-t border-border p-6 dark:border-white/5">
                                <a
                                    href={leave_request.attachments[0].url}
                                    className="flex items-center gap-2 text-sm font-medium text-primary hover:underline"
                                >
                                    <FileText className="size-4 shrink-0" />
                                    <span className="truncate">{leave_request.attachments[0].name}</span>
                                </a>
                            </div>
                        ) : null}
                    </CardContent>
                </Card>
            </div>

            {can_view_audit ? (
                <RecentActivityCard items={recent_activity} description="Latest changes for this leave request." />
            ) : null}

            {canModify ? (
                <LeaveRequestFormSheet
                    open={editOpen}
                    onOpenChange={setEditOpen}
                    leaveRequest={leave_request}
                    employees={employees}
                    leaveTypes={leave_types}
                    employeeLocked={!can.approve}
                    form={form}
                    onSubmit={submit}
                />
            ) : null}

            <LeaveRequestDeleteDialog
                open={isDeleteOpen}
                onOpenChange={setIsDeleteOpen}
                leaveRequest={leave_request}
                onConfirm={() => {
                    router.delete(`/attendance/leave-requests/${leave_request.id}`, {
                        onSuccess: () => router.visit('/attendance/leave-requests'),
                    });
                }}
            />

            <LeaveRequestRejectDialog
                open={isRejectOpen}
                onOpenChange={setIsRejectOpen}
                leaveRequest={leave_request}
                onSuccess={() => setIsRejectOpen(false)}
            />
        </Main>
    );
}
