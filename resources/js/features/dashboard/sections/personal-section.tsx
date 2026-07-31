import { Link } from '@inertiajs/react';
import { User, Calendar, FileText, Bell, CreditCard, CheckCircle } from 'lucide-react';
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
                title="My Personal Dashboard"
                description="Your self-service employee portal"
                icon={User}
            >
                <div className="rounded-xl border border-dashed border-border/70 p-6 text-center bg-muted/20">
                    <User className="mx-auto h-8 w-8 text-muted-foreground/60 mb-2" />
                    <h3 className="font-semibold text-sm text-foreground">No Linked Employee Record</h3>
                    <p className="text-xs text-muted-foreground max-w-md mx-auto mt-1">
                        Your user account is not linked to an employee profile in this company. If you are an employee, contact HR to associate your account.
                    </p>
                </div>
            </DashboardSection>
        );
    }

    const { employee, attendance_today, my_leave_balances = [], my_leave_requests = [], my_expiring_documents = [], my_announcements = [], my_payslips = [] } = data;

    return (
        <DashboardSection
            title="My Personal Dashboard"
            description={`Self-service details for ${employee.name} (${employee.employee_no})`}
            icon={User}
        >
            <div className="space-y-4">
                {/* Top Summary Banner: Employee Info & Attendance Today */}
                <div className="grid gap-4 md:grid-cols-3">
                    <div className="rounded-xl bg-card p-4 border border-border/50 shadow-sm flex items-center gap-3">
                        <div className="rounded-full bg-primary/10 p-3 text-primary shrink-0">
                            <User className="h-6 w-6" />
                        </div>
                        <div className="min-w-0">
                            <h4 className="font-bold text-sm text-foreground truncate">{employee.name}</h4>
                            <p className="text-xs text-muted-foreground">{employee.position || 'Employee'} • {employee.department || 'General'}</p>
                            <span className="inline-block mt-1 text-[11px] font-mono bg-muted px-2 py-0.5 rounded text-muted-foreground">
                                {employee.employee_no}
                            </span>
                        </div>
                    </div>

                    <div className="rounded-xl bg-card p-4 border border-border/50 shadow-sm flex items-center justify-between">
                        <div className="space-y-1">
                            <span className="text-xs font-semibold uppercase text-muted-foreground tracking-wider">Attendance Today</span>
                            <div className="flex items-center gap-2">
                                <span className={`inline-block h-2.5 w-2.5 rounded-full ${attendance_today ? 'bg-emerald-500' : 'bg-amber-500'}`} />
                                <span className="font-semibold text-sm capitalize">
                                    {attendance_today ? (attendance_today.status || 'Present') : 'Not Clocked In'}
                                </span>
                            </div>
                            {attendance_today?.clock_in && (
                                <p className="text-xs text-muted-foreground font-mono">
                                    In: {new Date(attendance_today.clock_in).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
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

                    <div className="rounded-xl bg-card p-4 border border-border/50 shadow-sm flex items-center justify-between">
                        <div className="space-y-1">
                            <span className="text-xs font-semibold uppercase text-muted-foreground tracking-wider">Leave Entitlement</span>
                            <p className="text-xl font-bold tracking-tight text-foreground">
                                {my_leave_balances.reduce((acc, b) => acc + (b.remaining_days || 0), 0)} <span className="text-xs font-normal text-muted-foreground">days remaining</span>
                            </p>
                        </div>
                        <Link
                            href={leaveRequestsIndex.url()}
                            className="text-xs font-semibold text-primary hover:underline"
                        >
                            Request Leave →
                        </Link>
                    </div>
                </div>

                {/* Sub-grid: Leave Balances & Announcements */}
                <div className="grid gap-4 md:grid-cols-2">
                    {/* Leave Balances List */}
                    <div className="rounded-xl bg-card p-4 border border-border/50 shadow-sm space-y-3">
                        <div className="flex items-center justify-between border-b pb-2">
                            <span className="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
                                <Calendar className="h-4 w-4 text-primary" />
                                My Leave Balances
                            </span>
                            <Link href={leaveRequestsIndex.url()} className="text-xs text-primary font-medium hover:underline">
                                View all
                            </Link>
                        </div>

                        {my_leave_balances.length === 0 ? (
                            <p className="text-xs text-muted-foreground py-2">No leave balances assigned.</p>
                        ) : (
                            <div className="space-y-2">
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {my_leave_balances.map((b) => (
                                        <div key={b.id} className="rounded-lg bg-muted/40 p-2.5 text-xs flex justify-between items-center">
                                            <span className="font-medium text-foreground truncate">{b.name}</span>
                                            <span className="font-bold text-primary shrink-0 ml-2">{b.remaining_days} days</span>
                                        </div>
                                    ))}
                                </div>
                                {my_leave_requests.length > 0 && (
                                    <div className="pt-2 border-t border-border/30 space-y-1">
                                        <span className="text-[11px] font-semibold text-muted-foreground uppercase">Recent Requests</span>
                                        {my_leave_requests.slice(0, 2).map((lr) => (
                                            <div key={lr.id} className="flex justify-between items-center text-[11px] text-muted-foreground">
                                                <span className="truncate">{lr.leave_type} ({lr.total_days}d)</span>
                                                <span className="font-medium capitalize">{lr.status}</span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        )}
                    </div>

                    {/* Personal Announcements Inbox */}
                    <div className="rounded-xl bg-card p-4 border border-border/50 shadow-sm space-y-3">
                        <div className="flex items-center justify-between border-b pb-2">
                            <span className="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
                                <Bell className="h-4 w-4 text-amber-500" />
                                My Announcements ({my_announcements.length})
                            </span>
                        </div>

                        {my_announcements.length === 0 ? (
                            <p className="text-xs text-muted-foreground py-2">No announcements received.</p>
                        ) : (
                            <div className="space-y-2">
                                {my_announcements.slice(0, 3).map((a) => (
                                    <Link key={a.id} href={a.url} className="block rounded-lg bg-muted/30 p-2.5 hover:bg-muted/60 transition-colors">
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="font-semibold text-xs text-foreground truncate">{a.title}</span>
                                            {!a.read_at && (
                                                <span className="h-2 w-2 rounded-full bg-amber-500 shrink-0" title="Unread" />
                                            )}
                                        </div>
                                        <p className="text-[11px] text-muted-foreground line-clamp-1 mt-0.5">{a.preview}</p>
                                    </Link>
                                ))}
                            </div>
                        )}
                    </div>
                </div>

                {/* Sub-grid: Expiring Documents & Payslips */}
                <div className="grid gap-4 md:grid-cols-2">
                    {/* My Expiring Documents */}
                    <div className="rounded-xl bg-card p-4 border border-border/50 shadow-sm space-y-3">
                        <div className="flex items-center justify-between border-b pb-2">
                            <span className="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
                                <FileText className="h-4 w-4 text-blue-500" />
                                My Expiring Documents
                            </span>
                            <Link href={documents.url()} className="text-xs text-primary font-medium hover:underline">
                                My Documents
                            </Link>
                        </div>

                        {my_expiring_documents.length === 0 ? (
                            <div className="flex items-center gap-2 text-xs text-emerald-600 dark:text-emerald-400 py-2">
                                <CheckCircle className="h-4 w-4" />
                                <span>All your documents are valid and up to date.</span>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                {my_expiring_documents.map((doc) => (
                                    <div key={doc.id} className="flex items-center justify-between rounded-lg bg-muted/40 p-2.5 text-xs">
                                        <span className="font-medium text-foreground truncate">{doc.title}</span>
                                        <span className={`font-semibold shrink-0 ml-2 ${doc.is_expired ? 'text-rose-600' : 'text-amber-600'}`}>
                                            {doc.is_expired ? 'Expired' : doc.expiry_date}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* My Recent Payslips */}
                    <div className="rounded-xl bg-card p-4 border border-border/50 shadow-sm space-y-3">
                        <div className="flex items-center justify-between border-b pb-2">
                            <span className="text-xs font-bold uppercase tracking-wider text-foreground flex items-center gap-1.5">
                                <CreditCard className="h-4 w-4 text-emerald-500" />
                                My Payslips
                            </span>
                            <Link href={payrollIndex.url()} className="text-xs text-primary font-medium hover:underline">
                                View Payroll
                            </Link>
                        </div>

                        {my_payslips.length === 0 ? (
                            <p className="text-xs text-muted-foreground py-2">No payslips available.</p>
                        ) : (
                            <div className="space-y-2">
                                {my_payslips.map((p) => (
                                    <div key={p.id} className="flex items-center justify-between rounded-lg bg-muted/40 p-2.5 text-xs">
                                        <span className="font-medium text-foreground truncate">{p.period_name}</span>
                                        <span className="font-bold text-foreground font-mono">
                                            {typeof p.net_salary === 'number' ? p.net_salary.toLocaleString('en-US', { minimumFractionDigits: 2 }) : p.net_salary}
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
