import { Link, usePage } from '@inertiajs/react';
import {
    ClipboardList,
    FilePenLine,
    FileStack,
    Folder,
    History,
    LayoutDashboard,
    SlidersHorizontal,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { useAuthPermissions } from '@/hooks/use-has-permission';
import {
    DOCUMENTS_MODULE_LABELS,
    documentsModuleSectionFromUrl,
    visibleDocumentsModuleSections,
} from '@/lib/documents-module-nav';
import { documents } from '@/routes/organization';
import {
    activity,
    configuration,
    generate,
    library,
    requests,
    templates,
} from '@/routes/organization/documents';

const destinations = {
    overview: { route: documents, icon: LayoutDashboard },
    library: { route: library, icon: Folder },
    templates: { route: templates, icon: ClipboardList },
    generate: { route: generate, icon: FileStack },
    requests: { route: requests, icon: FilePenLine },
    configuration: { route: configuration, icon: SlidersHorizontal },
    activity: { route: activity, icon: History },
};

export default function DocumentsLayout({ children }: { children: ReactNode }) {
    const { url } = usePage();
    const permissions = useAuthPermissions();
    const sections = visibleDocumentsModuleSections(permissions);
    const activeSection = documentsModuleSectionFromUrl(url);

    return (
        <div className="flex min-h-0 min-w-0 flex-1 flex-col">
            <div className="shrink-0 border-b bg-muted/20 px-4 pt-4">
                <div className="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <Folder className="size-4 text-primary" aria-hidden />
                    Documents
                    <span className="text-xs font-normal text-muted-foreground">
                        Workspace
                    </span>
                </div>
                <nav
                    aria-label="Documents workspace"
                    className="-mx-1 flex gap-1 overflow-x-auto px-1 pb-3"
                >
                    {sections.map((section) => {
                        const { route, icon: Icon } = destinations[section];
                        const isActive = activeSection === section;

                        return (
                            <Button
                                key={section}
                                variant={isActive ? 'secondary' : 'ghost'}
                                size="sm"
                                className="shrink-0"
                                asChild
                            >
                                <Link
                                    href={route()}
                                    aria-current={isActive ? 'page' : undefined}
                                >
                                    <Icon className="size-4" aria-hidden />
                                    {DOCUMENTS_MODULE_LABELS[section]}
                                </Link>
                            </Button>
                        );
                    })}
                </nav>
            </div>
            {children}
        </div>
    );
}
