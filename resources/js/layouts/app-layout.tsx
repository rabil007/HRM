import { Breadcrumbs } from '@/components/breadcrumbs';
import { AuthenticatedLayout } from '@/components/layout/authenticated-layout';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    return (
        <AuthenticatedLayout>
            <>
                {breadcrumbs.length > 0 && (
                    <header className="flex h-16 shrink-0 items-center gap-2 border-b px-4 transition-[width,height] ease-linear md:px-6">
                        <div className="flex items-center gap-2">
                            <SidebarTrigger className="-ml-1" />
                            <Breadcrumbs breadcrumbs={breadcrumbs} />
                        </div>
                    </header>
                )}
                {children}
            </>
        </AuthenticatedLayout>
    );
}
