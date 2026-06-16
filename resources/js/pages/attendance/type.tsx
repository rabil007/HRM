import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { RecentActivityCard } from '@/components/recent-activity-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { LeaveTypeFormSheet } from '@/features/attendance/types/components/leave-type-form-sheet';
import { leaveTypeToFormData  } from '@/features/attendance/types/types';
import type {LeaveType} from '@/features/attendance/types/types';
import { formatDisplayDate } from '@/lib/format-date';

type LeaveTypeDetail = LeaveType & {
    leave_requests_count: number;
    created_at: string | null;
    updated_at: string | null;
};

function Field({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex items-center justify-between gap-3 px-6 py-4">
            <div className="text-sm font-semibold text-muted-foreground/80">{label}</div>
            <div className="text-right text-sm font-bold">{value}</div>
        </div>
    );
}

export default function AttendanceTypeDetails({
    leave_type,
    recent_activity,
    can_view_audit,
    can,
}: {
    leave_type: LeaveTypeDetail;
    recent_activity: RecentActivityItem[];
    can_view_audit: boolean;
    can: { update: boolean };
}) {
    const [editOpen, setEditOpen] = useState(false);
    const form = useForm(leaveTypeToFormData(leave_type));

    const statusClass =
        leave_type.status === 'active'
            ? 'bg-emerald-500/10 text-emerald-700 border-emerald-500/20 dark:text-emerald-200'
            : 'bg-muted/60 text-muted-foreground border-border dark:bg-zinc-500/10 dark:text-zinc-200 dark:border-zinc-500/20';

    return (
        <Main>
            <Head title={leave_type.name} />

            <DetailsHeader
                kicker="Attendance"
                title={leave_type.name}
                description={`${leave_type.code} • ${leave_type.days_per_year} days per year`}
                backHref="/attendance/types"
                backLabel="Back to types"
                actions={
                    can.update ? (
                        <Button className="h-11 rounded-xl px-5" onClick={() => setEditOpen(true)}>
                            Edit
                        </Button>
                    ) : null
                }
            />

            <div className="grid gap-6 lg:grid-cols-2">
                <Card className="glass-card border-border bg-card dark:border-white/5 dark:bg-white/5">
                    <CardHeader className="flex flex-row items-start justify-between gap-4">
                        <div className="space-y-1">
                            <CardTitle className="text-xl font-bold tracking-tight">Overview</CardTitle>
                            <div className="text-sm text-muted-foreground/80">Leave type configuration.</div>
                        </div>
                        <Badge className={`border text-[10px] font-bold uppercase tracking-wider ${statusClass}`}>
                            {leave_type.status}
                        </Badge>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="divide-y divide-border dark:divide-white/5">
                            <Field label="Code" value={leave_type.code} />
                            <Field label="Days per year" value={String(leave_type.days_per_year)} />
                            <Field label="Carry forward" value={leave_type.carry_forward ? 'Yes' : 'No'} />
                            <Field label="Max carry days" value={String(leave_type.max_carry_days)} />
                            <div className="flex items-center justify-between gap-3 px-6 py-4">
                                <div className="text-sm font-semibold text-muted-foreground/80">Color</div>
                                <div className="flex items-center gap-2">
                                    <span
                                        className="inline-block h-5 w-5 rounded-full border border-border/60"
                                        style={{ backgroundColor: leave_type.color ?? '#94a3b8' }}
                                    />
                                    <span className="text-sm font-bold">{leave_type.color ?? '—'}</span>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card className="glass-card border-border bg-card dark:border-white/5 dark:bg-white/5">
                    <CardHeader>
                        <CardTitle className="text-xl font-bold tracking-tight">Quick info</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <div className="divide-y divide-border dark:divide-white/5">
                            <Field label="Leave requests" value={String(leave_type.leave_requests_count)} />
                            <Field label="Created" value={leave_type.created_at ? formatDisplayDate(leave_type.created_at) : '—'} />
                            <Field label="Updated" value={leave_type.updated_at ? formatDisplayDate(leave_type.updated_at) : '—'} />
                        </div>
                    </CardContent>
                </Card>
            </div>

            {can_view_audit ? (
                <RecentActivityCard items={recent_activity} description="Latest changes for this type." />
            ) : null}

            {can.update ? (
                <LeaveTypeFormSheet
                    open={editOpen}
                    onOpenChange={setEditOpen}
                    leaveType={leave_type}
                    form={form}
                    onSubmit={() => {
                        form.put(`/attendance/types/${leave_type.id}`, {
                            preserveScroll: true,
                            onSuccess: () => setEditOpen(false),
                        });
                    }}
                />
            ) : null}
        </Main>
    );
}
