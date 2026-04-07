import { Breadcrumbs } from '@/components/breadcrumbs';
import { AuthenticatedLayout } from '@/components/layout/authenticated-layout';
import type { BreadcrumbItem } from '@/types';
import { Header } from '@/components/layout/header';
import { TopNav } from '@/components/layout/top-nav';
import { ProfileDropdown } from '@/components/profile-dropdown';
import { Search } from '@/components/search';
import { ThemeSwitch } from '@/components/theme-switch';
import { ConfigDrawer } from '@/components/config-drawer';
import { dashboard } from '@/routes';
import { usePage } from '@inertiajs/react';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    const { url } = usePage();
    const placeholder = (key: string) => `${dashboard.url()}?module=${encodeURIComponent(key)}`;
    
    const navLinks = [
        {
            title: 'Overview',
            href: dashboard.url(),
            isActive: url === dashboard.url(),
            disabled: false,
        },
        {
            title: 'Employees',
            href: placeholder('employees.index'),
            isActive: url.includes('module=employees') || url.includes('employees'),
            disabled: false,
        },
        {
            title: 'Recruitment',
            href: placeholder('recruitment.job-postings'),
            isActive: url.includes('module=recruitment') || url.includes('recruitment'),
            disabled: false,
        },
        {
            title: 'Attendance',
            href: placeholder('attendance.records'),
            isActive: url.includes('module=attendance') || url.includes('attendance'),
            disabled: false,
        },
        {
            title: 'Leave',
            href: placeholder('leave.requests'),
            isActive: url.includes('module=leave') || url.includes('leave'),
            disabled: false,
        },
        {
            title: 'Payroll',
            href: placeholder('payroll.periods'),
            isActive: url.includes('module=payroll') || url.includes('payroll'),
            disabled: false,
        },
    ];

    return (
        <AuthenticatedLayout>
            <>
                <Header>
                    {breadcrumbs.length > 0 && (
                        <div className="mr-4 hidden md:block">
                            <Breadcrumbs breadcrumbs={breadcrumbs} />
                        </div>
                    )}
                    <TopNav links={navLinks} />
                    <div className="ms-auto flex items-center space-x-4">
                        <Search />
                        <ThemeSwitch />
                        <ConfigDrawer />
                        <ProfileDropdown />
                    </div>
                </Header>
                {children}
            </>
        </AuthenticatedLayout>
    );
}
