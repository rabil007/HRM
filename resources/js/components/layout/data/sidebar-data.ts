import {
    Command,
    Building2,
    CalendarRange,
    ClipboardList,
    LayoutDashboard,
    Landmark,
    Layers,
    MapPin,
    Megaphone,
    Users,
    Wallet,
    Settings,
    SlidersHorizontal,
    FileText,
    FileStack,
    FileSignature,
    IdCard,
    BadgeCheck,
    Activity,
    CalendarCheck2,
    CalendarDays,
    PiggyBank,
    Coins,
    Radio,
    Contact,
    CreditCard,
    GraduationCap,
    Ship,
    Waves,
    BarChart3,
    FilePenLine,
    History,
    Folder,
    ShieldCheck,
    Database,
    ListTodo,
    ScrollText,
} from 'lucide-react';
import { isSidebarUrlVisible, NO_PLATFORM_ACCESS } from '@/lib/nav-visibility';
import type { NavPlatformAccess } from '@/lib/nav-visibility';
import { getSettingsSidebarSubItems } from '@/lib/settings-nav';
import { dashboard, log } from '@/routes';
import { index as jobsIndex } from '@/routes/jobs';
import { index as mysqlIndex } from '@/routes/mysql';
import {
    bankAccounts,
    contracts,
    documents,
    seaServices,
    training,
} from '@/routes/organization';
import { index as crewMovementCorrections } from '@/routes/organization/crew-movement-corrections';
import {
    activity as documentsActivity,
    generate as documentsGenerate,
    library as documentsLibrary,
    requests as documentsRequests,
    templates as documentsTemplates,
    configuration as documentsConfiguration,
} from '@/routes/organization/documents';
import { index as crewMovementHistory } from '@/routes/organization/reports/crew-movement-history';
import type { SidebarData } from '../types';

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
                {
                    title: 'Companies',
                    url: '/organization/companies',
                    icon: Building2,
                },
                {
                    title: 'Branches',
                    url: '/organization/branches',
                    icon: MapPin,
                },
                {
                    title: 'Announcements',
                    url: '/organization/announcements',
                    icon: Megaphone,
                },
                {
                    title: 'Departments',
                    url: '/organization/departments',
                    icon: Layers,
                },
                {
                    title: 'Positions',
                    url: '/organization/positions',
                    icon: Landmark,
                },
                {
                    title: 'Activity logs',
                    url: '/organization/activity-logs',
                    icon: Activity,
                },
                {
                    title: 'Roles & permissions',
                    url: '/organization/roles',
                    icon: BadgeCheck,
                },
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
                {
                    title: 'Employees',
                    url: '/organization/employees',
                    icon: Users,
                },
                {
                    title: 'Documents',
                    icon: FileText,
                    items: [
                        {
                            title: 'Overview',
                            url: documents.url(),
                            icon: LayoutDashboard,
                        },
                        {
                            title: 'Library',
                            url: documentsLibrary.url(),
                            icon: Folder,
                        },
                        {
                            title: 'Generate & Send',
                            url: documentsGenerate.url(),
                            icon: FileStack,
                        },
                        {
                            title: 'Requests',
                            url: documentsRequests.url(),
                            icon: FilePenLine,
                        },
                        {
                            title: 'Templates',
                            url: documentsTemplates.url(),
                            icon: ClipboardList,
                        },
                        {
                            title: 'Document Types',
                            url: documentsConfiguration.url(),
                            icon: SlidersHorizontal,
                        },
                        {
                            title: 'Activity',
                            url: documentsActivity.url(),
                            icon: History,
                        },
                    ],
                },
                {
                    title: 'Contracts',
                    url: contracts.url(),
                    icon: FileSignature,
                },
                {
                    title: 'Bank Accounts',
                    url: bankAccounts.url(),
                    icon: CreditCard,
                },
                { title: 'Training', url: training.url(), icon: GraduationCap },
                { title: 'Sea Service', url: seaServices.url(), icon: Waves },
            ],
        },
        {
            title: 'Crew Operations',
            items: [
                {
                    title: 'Overview',
                    url: '/organization/crew-operations',
                    icon: LayoutDashboard,
                },
                {
                    title: 'Crew Assignments',
                    url: '/organization/crew',
                    icon: Users,
                },
                {
                    title: 'Planning',
                    url: '/organization/crew-planning',
                    icon: CalendarRange,
                },
                {
                    title: 'Vessels',
                    url: '/organization/vessels',
                    icon: Ship,
                },
                {
                    title: 'Vessel Manning',
                    url: '/organization/vessel-manning',
                    icon: ClipboardList,
                },
                {
                    title: 'Movement Corrections',
                    url: crewMovementCorrections.url(),
                    icon: FilePenLine,
                },
                {
                    title: 'Settings',
                    url: '/organization/crew-operations/settings',
                    icon: Settings,
                },
            ],
        },
        {
            title: 'Reports',
            items: [
                {
                    title: 'Crew Movement History',
                    url: crewMovementHistory.url(),
                    icon: BarChart3,
                },
            ],
        },
        {
            title: 'Hikvision',
            items: [
                { title: 'Persons', url: '/hikvision/persons', icon: Contact },
                {
                    title: 'Access Events',
                    url: '/hikvision/access-events',
                    icon: Radio,
                },
            ],
        },
        {
            title: 'Attendance',
            items: [
                {
                    title: 'Overview',
                    url: '/attendance/overview',
                    icon: LayoutDashboard,
                },
                {
                    title: 'Calendar',
                    url: '/attendance/calendar',
                    icon: CalendarDays,
                },
                {
                    title: 'Leave requests',
                    url: '/attendance/leave-requests',
                    icon: CalendarCheck2,
                },
                {
                    title: 'Attendance records',
                    url: '/attendance/records',
                    icon: CalendarCheck2,
                },
                { title: 'Types', url: '/attendance/types', icon: IdCard },
                {
                    title: 'Approval policies',
                    url: '/attendance/leave-approval-policies',
                    icon: ShieldCheck,
                },
            ],
        },
        {
            title: 'Payroll',
            items: [
                {
                    title: 'Overview',
                    url: '/payroll/overview',
                    icon: LayoutDashboard,
                },
                { title: 'Payroll', url: '/payroll', icon: Wallet },
                {
                    title: 'Payroll records',
                    url: '/payroll/records',
                    icon: PiggyBank,
                },
                {
                    title: 'Salary inputs',
                    url: '/payroll/salary-inputs',
                    icon: Coins,
                },
            ],
        },
        {
            title: 'Platform',
            items: [
                {
                    title: 'Logs',
                    url: log.url(),
                    icon: ScrollText,
                },
                {
                    title: 'Jobs',
                    url: jobsIndex.url(),
                    icon: ListTodo,
                },
                {
                    title: 'Database',
                    url: mysqlIndex.url(),
                    icon: Database,
                },
            ],
        },
    ],
};

export {
    canAccessSalaryInputs,
    canViewCrewOperations,
    canViewCrewOperationsOverview,
    canViewPayroll,
    has,
} from '@/lib/nav-visibility';

export function getSidebarData(
    permissions: string[],
    platform: NavPlatformAccess = NO_PLATFORM_ACCESS,
): SidebarData {
    const groups = baseSidebarData.navGroups
        .map((group) => {
            const items = group.items
                .map((item) => {
                    if ('items' in item && item.items) {
                        if (item.title === 'Settings') {
                            const filteredSub = getSettingsSidebarSubItems(
                                permissions,
                                platform,
                            );

                            if (!filteredSub.length) {
                                return null;
                            }

                            return {
                                ...item,
                                items: filteredSub,
                            };
                        }

                        const filteredSub = item.items.filter((sub) =>
                            isSidebarUrlVisible(sub.url, permissions, platform),
                        );

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

                    return isSidebarUrlVisible(item.url, permissions, platform)
                        ? item
                        : null;
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
