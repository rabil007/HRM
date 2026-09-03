import { Link } from '@inertiajs/react';
import {
    UserPlus,
    Upload,
    FileSignature,
    CheckCircle2,
    Calculator,
    Anchor,
    Megaphone,
    Zap,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { index as leaveRequestsIndex } from '@/routes/attendance/leave-requests';
import { contracts, documents } from '@/routes/organization';
import { create as createAnnouncement } from '@/routes/organization/announcements';
import { index as crewPlanningIndex } from '@/routes/organization/crew-planning';
import { create as createEmployee } from '@/routes/organization/employees';
import { index as payrollIndex } from '@/routes/payroll';
import type { DashboardCan } from '../dashboard-types';

type QuickActionsProps = {
    can: DashboardCan;
};

export function QuickActions({ can }: QuickActionsProps) {
    const hasAnyAction =
        can.employees_create ||
        can.documents_upload ||
        can.contracts_create ||
        can.attendance_leave_approve ||
        can.payroll_periods_create ||
        can.crew_planning_create ||
        can.announcements_publish;

    if (!hasAnyAction) {
        return null;
    }

    return (
        <section
            aria-labelledby="quick-actions-heading"
            className="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card/60 p-3 shadow-xs sm:flex-row sm:items-center"
        >
            <div className="flex shrink-0 items-center gap-2 px-1 sm:pr-2">
                <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <Zap className="h-4 w-4" />
                </div>
                <div>
                    <h2
                        id="quick-actions-heading"
                        className="text-xs font-semibold text-foreground"
                    >
                        Quick actions
                    </h2>
                    <p className="text-[11px] text-muted-foreground">
                        Common tasks
                    </p>
                </div>
            </div>

            <div className="no-scrollbar flex items-center gap-2 overflow-x-auto pb-0.5 sm:pb-0">
                {can.employees_create && (
                    <Button
                        asChild
                        size="sm"
                        variant="default"
                        className="h-9 shrink-0 gap-1.5 rounded-xl px-3 text-xs shadow-sm"
                    >
                        <Link href={createEmployee.url()}>
                            <UserPlus className="h-3.5 w-3.5" />
                            Add Employee
                        </Link>
                    </Button>
                )}

                {can.documents_upload && (
                    <Button
                        asChild
                        size="sm"
                        variant="outline"
                        className="h-9 shrink-0 gap-1.5 rounded-xl bg-background/70 px-3 text-xs"
                    >
                        <Link href={documents.url()}>
                            <Upload className="h-3.5 w-3.5 text-blue-500" />
                            Upload Document
                        </Link>
                    </Button>
                )}

                {can.contracts_create && (
                    <Button
                        asChild
                        size="sm"
                        variant="outline"
                        className="h-9 shrink-0 gap-1.5 rounded-xl bg-background/70 px-3 text-xs"
                    >
                        <Link href={contracts.url()}>
                            <FileSignature className="h-3.5 w-3.5 text-purple-500" />
                            New Contract
                        </Link>
                    </Button>
                )}

                {can.attendance_leave_approve && (
                    <Button
                        asChild
                        size="sm"
                        variant="outline"
                        className="h-9 shrink-0 gap-1.5 rounded-xl bg-background/70 px-3 text-xs"
                    >
                        <Link
                            href={leaveRequestsIndex.url({
                                query: { view: 'awaiting_my_approval' },
                            })}
                        >
                            <CheckCircle2 className="h-3.5 w-3.5 text-emerald-500" />
                            Approve Leave
                        </Link>
                    </Button>
                )}

                {can.payroll_periods_create && (
                    <Button
                        asChild
                        size="sm"
                        variant="outline"
                        className="h-9 shrink-0 gap-1.5 rounded-xl bg-background/70 px-3 text-xs"
                    >
                        <Link href={payrollIndex.url()}>
                            <Calculator className="h-3.5 w-3.5 text-indigo-500" />
                            Run Payroll
                        </Link>
                    </Button>
                )}

                {can.crew_planning_create && (
                    <Button
                        asChild
                        size="sm"
                        variant="outline"
                        className="h-9 shrink-0 gap-1.5 rounded-xl bg-background/70 px-3 text-xs"
                    >
                        <Link href={crewPlanningIndex.url()}>
                            <Anchor className="h-3.5 w-3.5 text-cyan-500" />
                            Plan Crew
                        </Link>
                    </Button>
                )}

                {can.announcements_publish && (
                    <Button
                        asChild
                        size="sm"
                        variant="outline"
                        className="h-9 shrink-0 gap-1.5 rounded-xl bg-background/70 px-3 text-xs"
                    >
                        <Link href={createAnnouncement.url()}>
                            <Megaphone className="h-3.5 w-3.5 text-amber-500" />
                            New Announcement
                        </Link>
                    </Button>
                )}
            </div>
        </section>
    );
}
