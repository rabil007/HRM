import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import { AnnouncementNotificationBell } from '@/components/announcement-notification-bell';
import { AppRefreshButton } from '@/components/app-refresh-button';
import { AppRefreshSync } from '@/components/app-refresh-sync';
import { ApplicationBrandingSync } from '@/components/application-branding-sync';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { ConfigDrawer } from '@/components/config-drawer';
import { AuthenticatedLayout } from '@/components/layout/authenticated-layout';
import { getTopNavLinks } from '@/components/layout/data/top-nav-data';
import { Header } from '@/components/layout/header';
import { TopNav } from '@/components/layout/top-nav';
import { NavigationFavoriteToggle } from '@/components/navigation-favorite-toggle';
import { ProfileDropdown } from '@/components/profile-dropdown';
import { Search } from '@/components/search';
import { ThemeSwitch } from '@/components/theme-switch';
import { useAuthPermissions } from '@/hooks/use-has-permission';
import { version as appVersion } from '@/routes/app';
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
                {/* Must live inside Inertia's page tree — usePage fails in withApp siblings. */}
                <AppRefreshSync versionUrl={appVersion.url()} />
                <ApplicationBrandingSync />
                <Header>
                    {breadcrumbs.length > 0 && (
                        <div className="mr-4 hidden md:block">
                            <Breadcrumbs breadcrumbs={breadcrumbs} />
                        </div>
                    )}
                    <NavigationFavoriteToggle />
                    <TopNav links={navLinks} />
                    <div className="ms-auto flex items-center gap-2 sm:gap-4">
                        <Search />
                        <AnnouncementNotificationBell />
                        <AppRefreshButton />
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
