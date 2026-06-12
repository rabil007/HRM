import {
    Command,
    Building2,
    CalendarDays,
    ClipboardList,
    LayoutDashboard,
    Landmark,
    Layers,
    MapPin,
    NotebookTabs,
    Receipt,
    Users,
    Wallet,
    Settings,
    BriefcaseBusiness,
    FileText,
    IdCard,
    BadgeCheck,
    Activity,
    CalendarCheck2,
    PiggyBank,
    Radio,
    Contact,
    Ship,
} from 'lucide-react';
import { getSettingsSidebarSubItems } from '@/lib/settings-nav';
import { dashboard } from '@/routes';
import { documents } from '@/routes/organization';
import type { SidebarData } from '../types';

const placeholder = (key: string) =>
    `${dashboard.url()}?module=${encodeURIComponent(key)}`;

const baseSidebarData: SidebarData = {
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
                    items: [],
                },
            ],
        },
        {
            title: 'Organization',
            items: [
                { title: 'Companies', url: '/organization/companies', icon: Building2 },
                { title: 'Branches', url: '/organization/branches', icon: MapPin },
                { title: 'Departments', url: '/organization/departments', icon: Layers },
                { title: 'Positions', url: '/organization/positions', icon: Landmark },
                { title: 'Activity logs', url: '/organization/activity-logs', icon: Activity },
                { title: 'Roles & permissions', url: '/organization/roles', icon: BadgeCheck },
                { title: 'Users', url: '/organization/users', icon: Users },
                {
                    title: 'Employee templates',
                    url: '/organization/templates/employee-profile',
                    icon: ClipboardList,
                },
            ],
        },
        {
            title: 'Employees',
            items: [
                { title: 'Employee', url: '/organization/employees', icon: Users },
                { title: 'Documents', url: documents.url(), icon: FileText },
            ],
        },
        {
            title: 'Crew Operations',
            items: [
                { title: 'Deployments', url: '/organization/crew-deployments', icon: Ship },
            ],
        },
        {
            title: 'Hikvision',
            items: [
                { title: 'Persons', url: '/hikvision/persons', icon: Contact },
                { title: 'Access Events', url: '/hikvision/access-events', icon: Radio },
            ],
        },
        {
            title: 'Attendance',
            items: [
                { title: 'Attendance types', url: '/attendance/types', icon: IdCard },
                { title: 'Attendance records', url: placeholder('attendance.records'), icon: CalendarCheck2 },
                { title: 'Public holidays', url: placeholder('attendance.public-holidays'), icon: CalendarDays },
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
            title: 'Leave',
            items: [
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

function has(permissions: string[], permission: string): boolean {
    return permissions.includes(permission);
}

export function getSidebarData(permissions: string[]): SidebarData {
    const groups = baseSidebarData.navGroups
        .map((group) => {
            const items = group.items
                .map((item) => {
                    if ('items' in item && item.items) {
                        if (item.title === 'Settings') {
                            const filteredSub = getSettingsSidebarSubItems(permissions);

                            if (!filteredSub.length) {
                                return null;
                            }

                            return {
                                ...item,
                                items: filteredSub,
                            };
                        }

                        const filteredSub = item.items.filter((sub) => {
                            if (sub.url === '/organization/templates/employee-profile') {
                                return has(permissions, 'employee_profile_templates.view');
                            }

                            return true;
                        });

                        if (!filteredSub.length) {
                            return null;
                        }

                        return {
                            ...item,
                            items: filteredSub,
                        };
                    }

                    if (!('url' in item) || !item.url) {
                        return item;
                    }

                    switch (item.url) {
                        case '/organization/companies':
                            return has(permissions, 'companies.view') ? item : null;
                        case '/organization/branches':
                            return has(permissions, 'branches.view') ? item : null;
                        case '/organization/departments':
                            return has(permissions, 'departments.view') ? item : null;
                        case '/organization/positions':
                            return has(permissions, 'positions.view') ? item : null;
                        case '/organization/employees':
                            return has(permissions, 'employees.view') ? item : null;
                        case '/organization/crew-deployments':
                            return has(permissions, 'crew_operations.deployments.view') ? item : null;
                        case documents.url():
                            return has(permissions, 'documents.view') ? item : null;
                        case '/organization/roles':
                            return has(permissions, 'roles.view') ? item : null;
                        case '/organization/users':
                            return has(permissions, 'users.view') ? item : null;
                        case '/organization/activity-logs':
                            return has(permissions, 'audit.view') ? item : null;
                        case '/organization/templates/employee-profile':
                            return has(permissions, 'employee_profile_templates.view')
                                ? item
                                : null;
                        case '/hikvision/persons':
                            return has(permissions, 'hikvision.persons.view') ? item : null;
                        case '/hikvision/access-events':
                            return has(permissions, 'hikvision.events.view') ? item : null;
                        case '/attendance/types':
                            return has(permissions, 'attendance.types.view') ? item : null;
                        default:
                            return item;
                    }
                })
                .filter(Boolean);

            if (!items.length) {
                return null;
            }

            return {
                ...group,
                items,
            };
        })
        .filter(Boolean);

    return {
        ...baseSidebarData,
        navGroups: groups,
    } as SidebarData;
}

export const sidebarData = baseSidebarData;
