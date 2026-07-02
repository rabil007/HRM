import { useMemo } from 'react';
import { usePage } from '@inertiajs/react';
import { ApplicationBrandingSync } from '@/components/application-branding-sync';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { ConfigDrawer } from '@/components/config-drawer';
import { AuthenticatedLayout } from '@/components/layout/authenticated-layout';
import { getTopNavLinks } from '@/components/layout/data/top-nav-data';
import { Header } from '@/components/layout/header';
import { TopNav } from '@/components/layout/top-nav';
import { ProfileDropdown } from '@/components/profile-dropdown';
import { Search } from '@/components/search';
import { ThemeSwitch } from '@/components/theme-switch';
import { useAuthPermissions } from '@/hooks/use-has-permission';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    const { url } = usePage();
    const permissions = useAuthPermissions();
    const navLinks = useMemo(
        () => getTopNavLinks(permissions, url),
        [permissions, url],
    );

    return (
        <AuthenticatedLayout>
            <>
                <ApplicationBrandingSync />
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
