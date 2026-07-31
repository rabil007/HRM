import { Link } from '@inertiajs/react';
import {
    UserPlus,
    Upload,
    FileSignature,
    CheckCircle2,
    Calculator,
    Anchor,
    Megaphone,
    FileCheck,
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
        can.announcements_publish ||
        can.signatures_review;

    if (!hasAnyAction) {
        return null;
    }

    return (
        <div className="flex flex-wrap items-center gap-2.5">
            <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mr-1">
                Quick Actions:
            </span>

            {can.employees_create && (
                <Button asChild size="sm" variant="default" className="h-8 gap-1.5 text-xs rounded-lg shadow-sm">
                    <Link href={createEmployee.url()}>
                        <UserPlus className="h-3.5 w-3.5" />
                        Add Employee
                    </Link>
                </Button>
            )}

            {can.documents_upload && (
                <Button asChild size="sm" variant="outline" className="h-8 gap-1.5 text-xs rounded-lg bg-card">
                    <Link href={documents.url()}>
                        <Upload className="h-3.5 w-3.5 text-blue-500" />
                        Upload Document
                    </Link>
                </Button>
            )}

            {can.contracts_create && (
                <Button asChild size="sm" variant="outline" className="h-8 gap-1.5 text-xs rounded-lg bg-card">
                    <Link href={contracts.url()}>
                        <FileSignature className="h-3.5 w-3.5 text-purple-500" />
                        New Contract
                    </Link>
                </Button>
            )}

            {can.attendance_leave_approve && (
                <Button asChild size="sm" variant="outline" className="h-8 gap-1.5 text-xs rounded-lg bg-card">
                    <Link href={leaveRequestsIndex.url({ query: { view: 'awaiting_my_approval' } })}>
                        <CheckCircle2 className="h-3.5 w-3.5 text-emerald-500" />
                        Approve Leave
                    </Link>
                </Button>
            )}

            {can.payroll_periods_create && (
                <Button asChild size="sm" variant="outline" className="h-8 gap-1.5 text-xs rounded-lg bg-card">
                    <Link href={payrollIndex.url()}>
                        <Calculator className="h-3.5 w-3.5 text-indigo-500" />
                        Run Payroll
                    </Link>
                </Button>
            )}

            {can.crew_planning_create && (
                <Button asChild size="sm" variant="outline" className="h-8 gap-1.5 text-xs rounded-lg bg-card">
                    <Link href={crewPlanningIndex.url()}>
                        <Anchor className="h-3.5 w-3.5 text-cyan-500" />
                        Plan Crew
                    </Link>
                </Button>
            )}

            {can.announcements_publish && (
                <Button asChild size="sm" variant="outline" className="h-8 gap-1.5 text-xs rounded-lg bg-card">
                    <Link href={createAnnouncement.url()}>
                        <Megaphone className="h-3.5 w-3.5 text-amber-500" />
                        New Announcement
                    </Link>
                </Button>
            )}

            {can.signatures_review && (
                <Button asChild size="sm" variant="outline" className="h-8 gap-1.5 text-xs rounded-lg bg-card">
                    <Link href={documents.url()}>
                        <FileCheck className="h-3.5 w-3.5 text-emerald-600" />
                        Review Signatures
                    </Link>
                </Button>
            )}
        </div>
    );
}
