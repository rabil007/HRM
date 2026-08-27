import { Link, usePage } from '@inertiajs/react';
import { tabs as dsTabs } from '@/lib/design-system';
import {
    DOCUMENTS_MODULE_LABELS,
    documentsModuleSectionFromUrl,
    visibleDocumentsModuleSections,
} from '@/lib/documents-module-nav';
import type { DocumentsModuleSection } from '@/lib/documents-module-nav';
import { cn } from '@/lib/utils';
import { documents } from '@/routes/organization';
import {
    activity,
    generate,
    library,
    requests,
    templates,
} from '@/routes/organization/documents';
import type { Auth } from '@/types/auth';

const SECTION_URLS: Record<DocumentsModuleSection, () => string> = {
    overview: () => documents.url(),
    library: () => library.url(),
    generate: () => generate.url(),
    requests: () => requests.url(),
    templates: () => templates.url(),
    activity: () => activity.url(),
};

export function DocumentsModuleNav() {
    const page = usePage();
    const auth = page.props.auth as Auth | undefined;
    const permissions = auth?.permissions ?? [];
    const platformView = auth?.platform?.view ?? false;
    const sections = visibleDocumentsModuleSections(permissions, platformView);
    const active =
        documentsModuleSectionFromUrl(page.url) ?? sections[0] ?? null;

    if (sections.length === 0) {
        return null;
    }

    return (
        <nav
            aria-label="Documents module"
            className="hide-scrollbar mb-6 overflow-x-auto"
        >
            <div className={cn(dsTabs.list, 'min-w-max flex-nowrap')}>
                {sections.map((section) => {
                    const isActive = section === active;

                    return (
                        <Link
                            key={section}
                            href={SECTION_URLS[section]()}
                            prefetch="click"
                            aria-current={isActive ? 'page' : undefined}
                            data-state={isActive ? 'active' : 'inactive'}
                            className={cn(
                                dsTabs.trigger,
                                'inline-flex items-center no-underline',
                            )}
                        >
                            {DOCUMENTS_MODULE_LABELS[section]}
                        </Link>
                    );
                })}
            </div>
        </nav>
    );
}
