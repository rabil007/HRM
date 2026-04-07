import { AppSidebar } from '@/components/layout/app-sidebar';
import { SkipToMain } from '@/components/skip-to-main';
import { SidebarInset, SidebarProvider } from '@/components/ui/sidebar';
import { LayoutProvider } from '@/context/layout-provider';
import { SearchProvider } from '@/context/search-provider';
import { getCookie } from '@/lib/cookies';
import { cn } from '@/lib/utils';

type AuthenticatedLayoutProps = {
    children: React.ReactNode;
};

export function AuthenticatedLayout({ children }: AuthenticatedLayoutProps) {
    const defaultOpen = getCookie('sidebar_state') !== 'false';

    return (
        <SearchProvider>
            <LayoutProvider>
                <SidebarProvider defaultOpen={defaultOpen}>
                    <SkipToMain />
                    <AppSidebar />
                    <SidebarInset
                        className={cn(
                            '@container/content',
                            'has-data-[layout=fixed]:h-svh',
                            'peer-data-[variant=inset]:has-data-[layout=fixed]:h-[calc(100svh-(var(--spacing)*4))]',
                        )}
                    >
                        {children}
                    </SidebarInset>
                </SidebarProvider>
            </LayoutProvider>
        </SearchProvider>
    );
}
