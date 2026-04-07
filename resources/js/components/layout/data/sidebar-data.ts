import {
    Command,
    Building2,
    CalendarDays,
    ClipboardList,
    LayoutDashboard,
    Landmark,
    Layers,
    LifeBuoy,
    MapPin,
    NotebookTabs,
    Receipt,
    Shield,
    Timer,
    Users,
    Wallet,
    Palette,
    Settings,
    BriefcaseBusiness,
    FileText,
    IdCard,
    BadgeCheck,
    CalendarCheck2,
    PiggyBank,
    UserCog,
} from 'lucide-react';
import { dashboard } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { SidebarData } from '../types';

const placeholder = (key: string) =>
    `${dashboard.url()}?module=${encodeURIComponent(key)}`;

export const sidebarData: SidebarData = {
    teams: [
        {
            name: 'OMS-HRM',
            logo: Command,
            plan: 'Human Resources',
        },
    ],
    navGroups: [
        {
            title: 'General',
            items: [
                {
                    title: 'Dashboard',
                    url: dashboard.url(),
                    icon: LayoutDashboard,
                },
                {
                    title: 'Settings',
                    icon: Settings,
                    items: [
                        {
                            title: 'Profile',
                            url: editProfile.url(),
                            icon: UserCog,
                        },
                        {
                            title: 'Security',
                            url: editSecurity.url(),
                            icon: Shield,
                        },
                        {
                            title: 'Appearance',
                            url: editAppearance.url(),
                            icon: Palette,
                        },
                    ],
                },
            ],
        },
        {
            title: 'Organization',
            items: [
                { title: 'Companies', url: placeholder('organization.companies'), icon: Building2 },
                { title: 'Branches', url: placeholder('organization.branches'), icon: MapPin },
                { title: 'Departments', url: placeholder('organization.departments'), icon: Layers },
                { title: 'Positions', url: placeholder('organization.positions'), icon: Landmark },
                { title: 'Roles & permissions', url: placeholder('organization.roles'), icon: BadgeCheck },
                { title: 'Users', url: placeholder('organization.users'), icon: Users },
            ],
        },
        {
            title: 'Employees',
            items: [
                { title: 'Employee directory', url: placeholder('employees.index'), icon: Users },
                { title: 'Documents', url: placeholder('employees.documents'), icon: FileText },
            ],
        },
        {
            title: 'Recruitment',
            items: [
                { title: 'Job postings', url: placeholder('recruitment.job-postings'), icon: BriefcaseBusiness },
                { title: 'Candidates', url: placeholder('recruitment.candidates'), icon: Users },
                { title: 'Interviews', url: placeholder('recruitment.interviews'), icon: NotebookTabs },
                { title: 'Offers', url: placeholder('recruitment.offers'), icon: ClipboardList },
            ],
        },
        {
            title: 'Onboarding',
            items: [
                { title: 'Templates', url: placeholder('onboarding.templates'), icon: ClipboardList },
                { title: 'Onboarding records', url: placeholder('onboarding.records'), icon: ClipboardList },
                { title: 'Offboarding records', url: placeholder('offboarding.records'), icon: LifeBuoy },
            ],
        },
        {
            title: 'Attendance',
            items: [
                { title: 'Shifts', url: placeholder('attendance.shifts'), icon: Timer },
                { title: 'Employee shifts', url: placeholder('attendance.employee-shifts'), icon: CalendarDays },
                { title: 'Attendance records', url: placeholder('attendance.records'), icon: CalendarCheck2 },
                { title: 'Public holidays', url: placeholder('attendance.public-holidays'), icon: CalendarDays },
            ],
        },
        {
            title: 'Leave',
            items: [
                { title: 'Leave types', url: placeholder('leave.types'), icon: IdCard },
                { title: 'Leave balances', url: placeholder('leave.balances'), icon: Wallet },
                { title: 'Leave requests', url: placeholder('leave.requests'), icon: CalendarCheck2 },
            ],
        },
        {
            title: 'Payroll',
            items: [
                { title: 'Payroll periods', url: placeholder('payroll.periods'), icon: Receipt },
                { title: 'Payroll records', url: placeholder('payroll.records'), icon: PiggyBank },
                { title: 'Salary adjustments', url: placeholder('payroll.adjustments'), icon: Wallet },
            ],
        },
    ],
};
