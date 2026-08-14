import { Link } from '@inertiajs/react';
import {
    User,
    Calendar,
    FileText,
    Bell,
    CreditCard,
    CheckCircle,
} from 'lucide-react';
import { index as leaveRequestsIndex } from '@/routes/attendance/leave-requests';
import { index as attendanceRecordsIndex } from '@/routes/attendance/records';
import { documents } from '@/routes/organization';
import { index as payrollIndex } from '@/routes/payroll';
import { DashboardSection } from '../components/dashboard-section';
import type { PersonalDashboard } from '../dashboard-types';

type PersonalSectionProps = {
    data?: PersonalDashboard;
};

export function PersonalSection({ data }: PersonalSectionProps) {
    if (!data) {
        return null;
    }

    if (!data.has_linked_employee || !data.employee) {
        return (
            <DashboardSection
                title="My workspace"
                description="Your self-service employee portal"
                icon={User}
            >
                <div className="rounded-2xl border border-dashed border-border/70 bg-muted/20 p-8 text-center">
                    <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-muted text-muted-foreground">
                        <User className="h-5 w-5" />
                    </div>
                    <h3 className="text-sm font-semibold text-foreground">
                        No linked employee record
                    </h3>
                    <p className="mx-auto mt-1 max-w-md text-xs leading-5 text-muted-foreground">
                        Your user account is not linked to an employee profile
                        in this company. If you are an employee, contact HR to
                        associate your account.
                    </p>
                </div>
            </DashboardSection>
        );
    }

    const {
        employee,
        is_active_workforce = true,
        attendance_today,
        my_leave_balances = [],
        my_leave_requests = [],
        my_expiring_documents = [],
        my_announcements = [],
        my_payslips = [],
    } = data;

    return (
        <DashboardSection
            title="My workspace"
            description={`Self-service details for ${employee.name} (${employee.employee_no})`}
            icon={User}
        >
            <div className="space-y-4">
                {!is_active_workforce && (
                    <div className="rounded-2xl border border-amber-500/30 bg-amber-500/8 p-4 text-sm text-amber-950 dark:text-amber-100">
                        This employee record is no longer active. Historical
                        payslips, documents, and leave remain available. Current
                        attendance and leave requests are disabled.
                    </div>
                )}
                <div className="grid gap-4 md:grid-cols-3">
                    <div className="flex items-center gap-3 rounded-2xl border border-primary/15 bg-linear-to-br from-primary/8 via-card to-card p-4 shadow-sm">
                        <div className="shrink-0 rounded-2xl bg-primary/10 p-3 text-primary">
                            <User className="h-6 w-6" />
                        </div>
                        <div className="min-w-0">
                            <h4 className="truncate text-sm font-bold text-foreground">
                                {employee.name}
                            </h4>
                            <p className="text-xs text-muted-foreground">
                                {employee.position || 'Employee'} •{' '}
                                {employee.department || 'General'}
                            </p>
                            <span className="mt-1 inline-block rounded bg-muted px-2 py-0.5 font-mono text-[11px] text-muted-foreground">
                                {employee.employee_no}
                            </span>
                        </div>
                    </div>

                    <div className="flex items-center justify-between rounded-2xl border border-border/70 bg-card/80 p-4 shadow-sm">
                        <div className="space-y-1">
                            <span className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                Attendance Today
                            </span>
                            <div className="flex items-center gap-2">
                                <span
                                    className={`inline-block h-2.5 w-2.5 rounded-full ${attendance_today ? 'bg-emerald-500' : 'bg-amber-500'}`}
                                />
                                <span className="text-sm font-semibold capitalize">
                                    {attendance_today
                                        ? attendance_today.status || 'Present'
                                        : 'Not Clocked In'}
                                </span>
                            </div>
                            {attendance_today?.clock_in && (
                                <p className="font-mono text-xs text-muted-foreground">
                                    In:{' '}
                                    {new Date(
                                        attendance_today.clock_in,
                                    ).toLocaleTimeString([], {
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    })}
                                </p>
                            )}
                        </div>
                        <Link
                            href={attendanceRecordsIndex.url()}
                            className="text-xs font-semibold text-primary hover:underline"
                        >
                            View Record →
                        </Link>
                    </div>

                    <div className="flex items-center justify-between rounded-2xl border border-border/70 bg-card/80 p-4 shadow-sm">
                        <div className="space-y-1">
                            <span className="text-xs font-semibold tracking-wider text-muted-foreground uppercase">
                                Leave Entitlement
                            </span>
                            <p className="text-xl font-bold tracking-tight text-foreground">
                                {my_leave_balances.reduce(
                                    (acc, b) => acc + (b.remaining_days || 0),
                                    0,
                                )}{' '}
                                <span className="text-xs font-normal text-muted-foreground">
                                    days remaining
                                </span>
                            </p>
                        </div>
                        {is_active_workforce ? (
                            <Link
                                href={leaveRequestsIndex.url()}
                                className="text-xs font-semibold text-primary hover:underline"
                            >
                                Request Leave →
                            </Link>
                        ) : (
                            <span className="text-xs text-muted-foreground">
                                Leave requests disabled
                            </span>
                        )}
                    </div>
                </div>

                {/* Sub-grid: Leave Balances & Announcements */}
                <div className="grid gap-4 md:grid-cols-2">
                    {/* Leave Balances List */}
                    <div className="space-y-3 rounded-2xl border border-border/70 bg-card/80 p-4 shadow-sm">
                        <div className="flex items-center justify-between border-b pb-2">
                            <span className="flex items-center gap-1.5 text-xs font-bold tracking-wider text-foreground uppercase">
                                <Calendar className="h-4 w-4 text-primary" />
                                My Leave Balances
                            </span>
                            <Link
                                href={leaveRequestsIndex.url()}
                                className="text-xs font-medium text-primary hover:underline"
                            >
                                View all
                            </Link>
                        </div>

                        {my_leave_balances.length === 0 ? (
                            <p className="py-2 text-xs text-muted-foreground">
                                No leave balances assigned.
                            </p>
                        ) : (
                            <div className="space-y-2">
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {my_leave_balances.map((b) => (
                                        <div
                                            key={b.id}
                                            className="flex items-center justify-between rounded-lg bg-muted/40 p-2.5 text-xs"
                                        >
                                            <span className="truncate font-medium text-foreground">
                                                {b.name}
                                            </span>
                                            <span className="ml-2 shrink-0 font-bold text-primary">
                                                {b.remaining_days} days
                                            </span>
                                        </div>
                                    ))}
                                </div>
                                {my_leave_requests.length > 0 && (
                                    <div className="space-y-1 border-t border-border/30 pt-2">
                                        <span className="text-[11px] font-semibold text-muted-foreground uppercase">
                                            Recent Requests
                                        </span>
                                        {my_leave_requests
                                            .slice(0, 2)
                                            .map((lr) => (
                                                <div
                                                    key={lr.id}
                                                    className="flex items-center justify-between text-[11px] text-muted-foreground"
                                                >
                                                    <span className="truncate">
                                                        {lr.leave_type} (
                                                        {lr.total_days}d)
                                                    </span>
                                                    <span className="font-medium capitalize">
                                                        {lr.status}
                                                    </span>
                                                </div>
                                            ))}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>

                    {/* Personal Announcements Inbox */}
                    <div className="space-y-3 rounded-2xl border border-border/70 bg-card/80 p-4 shadow-sm">
                        <div className="flex items-center justify-between border-b pb-2">
                            <span className="flex items-center gap-1.5 text-xs font-bold tracking-wider text-foreground uppercase">
                                <Bell className="h-4 w-4 text-amber-500" />
                                My Announcements ({my_announcements.length})
                            </span>
                        </div>

                        {my_announcements.length === 0 ? (
                            <p className="py-2 text-xs text-muted-foreground">
                                No announcements received.
                            </p>
                        ) : (
                            <div className="space-y-2">
                                {my_announcements.slice(0, 3).map((a) => (
                                    <Link
                                        key={a.id}
                                        href={a.url}
                                        className="block rounded-lg bg-muted/30 p-2.5 transition-colors hover:bg-muted/60"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="truncate text-xs font-semibold text-foreground">
                                                {a.title}
                                            </span>
                                            {!a.read_at && (
                                                <span
                                                    className="h-2 w-2 shrink-0 rounded-full bg-amber-500"
                                                    title="Unread"
                                                />
                                            )}
                                        </div>
                                        <p className="mt-0.5 line-clamp-1 text-[11px] text-muted-foreground">
                                            {a.preview}
                                        </p>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </div>
                </div>

                {/* Sub-grid: Expiring Documents & Payslips */}
                <div className="grid gap-4 md:grid-cols-2">
                    {/* My Expiring Documents */}
                    <div className="space-y-3 rounded-2xl border border-border/70 bg-card/80 p-4 shadow-sm">
                        <div className="flex items-center justify-between border-b pb-2">
                            <span className="flex items-center gap-1.5 text-xs font-bold tracking-wider text-foreground uppercase">
                                <FileText className="h-4 w-4 text-blue-500" />
                                My Expiring Documents
                            </span>
                            <Link
                                href={documents.url()}
                                className="text-xs font-medium text-primary hover:underline"
                            >
                                My Documents
                            </Link>
                        </div>

                        {my_expiring_documents.length === 0 ? (
                            <div className="flex items-center gap-2 py-2 text-xs text-emerald-600 dark:text-emerald-400">
                                <CheckCircle className="h-4 w-4" />
                                <span>
                                    All your documents are valid and up to date.
                                </span>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                {my_expiring_documents.map((doc) => (
                                    <div
                                        key={doc.id}
                                        className="flex items-center justify-between rounded-lg bg-muted/40 p-2.5 text-xs"
                                    >
                                        <span className="truncate font-medium text-foreground">
                                            {doc.title}
                                        </span>
                                        <span
                                            className={`ml-2 shrink-0 font-semibold ${doc.is_expired ? 'text-rose-600' : 'text-amber-600'}`}
                                        >
                                            {doc.is_expired
                                                ? 'Expired'
                                                : doc.expiry_date}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* My Recent Payslips */}
                    <div className="space-y-3 rounded-2xl border border-border/70 bg-card/80 p-4 shadow-sm">
                        <div className="flex items-center justify-between border-b pb-2">
                            <span className="flex items-center gap-1.5 text-xs font-bold tracking-wider text-foreground uppercase">
                                <CreditCard className="h-4 w-4 text-emerald-500" />
                                My Payslips
                            </span>
                            <Link
                                href={payrollIndex.url()}
                                className="text-xs font-medium text-primary hover:underline"
                            >
                                View Payroll
                            </Link>
                        </div>

                        {my_payslips.length === 0 ? (
                            <p className="py-2 text-xs text-muted-foreground">
                                No payslips available.
                            </p>
                        ) : (
                            <div className="space-y-2">
                                {my_payslips.map((p) => (
                                    <div
                                        key={p.id}
                                        className="flex items-center justify-between rounded-lg bg-muted/40 p-2.5 text-xs"
                                    >
                                        <span className="truncate font-medium text-foreground">
                                            {p.period_name}
                                        </span>
                                        <span className="font-mono font-bold text-foreground">
                                            {typeof p.net_salary === 'number'
                                                ? p.net_salary.toLocaleString(
                                                      'en-US',
                                                      {
                                                          minimumFractionDigits: 2,
                                                      },
                                                  )
                                                : p.net_salary}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </DashboardSection>
    );
}
