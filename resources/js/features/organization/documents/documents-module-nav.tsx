import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { documents } from '@/routes/organization';
import {
    activity as documentsActivity,
    generate as documentsGenerate,
    library as documentsLibrary,
    requests as documentsRequests,
    templates as documentsTemplates,
} from '@/routes/organization/documents';

export type DocumentsModuleSection =
    | 'overview'
    | 'library'
    | 'generate'
    | 'requests'
    | 'templates'
    | 'activity';

type NavItem = {
    key: DocumentsModuleSection;
    label: string;
    href: string;
    permission: string | string[];
};

const NAV_ITEMS: NavItem[] = [
    {
        key: 'overview',
        label: 'Overview',
        href: documents.url(),
        permission: 'documents.view',
    },
    {
        key: 'library',
        label: 'Library',
        href: documentsLibrary.url(),
        permission: 'documents.view',
    },
    {
        key: 'generate',
        label: 'Generate & Send',
        href: documentsGenerate.url(),
        permission: 'bulk_documents.view',
    },
    {
        key: 'requests',
        label: 'Requests',
        href: documentsRequests.url(),
        permission: 'bulk_documents.view',
    },
    {
        key: 'templates',
        label: 'Templates',
        href: documentsTemplates.url(),
        permission: [
            'documents.view',
            'bulk_documents.view',
            'settings.application.view',
        ],
    },
    {
        key: 'activity',
        label: 'Activity',
        href: documentsActivity.url(),
        permission: 'bulk_documents.view',
    },
];

function canAccess(
    permissions: string[],
    required: string | string[],
): boolean {
    const needed = Array.isArray(required) ? required : [required];

    return needed.some((permission) => permissions.includes(permission));
}

export function DocumentsModuleNav({
    active,
    className,
}: {
    active: DocumentsModuleSection;
    className?: string;
}) {
    const page = usePage<{ auth?: { permissions?: string[] } }>();
    const permissions = page.props.auth?.permissions ?? [];

    const items = NAV_ITEMS.filter((item) =>
        canAccess(permissions, item.permission),
    );

    if (items.length <= 1) {
        return null;
    }

    return (
        <nav
            aria-label="Documents sections"
            className={cn('mb-6 overflow-x-auto', className)}
        >
            <div className="inline-flex min-w-full gap-1 rounded-xl border border-border/40 bg-muted/40 p-1 sm:min-w-0">
                {items.map((item) => {
                    const isActive = item.key === active;

                    return (
                        <Link
                            key={item.key}
                            href={item.href}
                            prefetch="click"
                            className={cn(
                                'shrink-0 rounded-lg px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                                isActive
                                    ? 'bg-background text-foreground shadow-sm ring-1 ring-border/60'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                            aria-current={isActive ? 'page' : undefined}
                        >
                            {item.label}
                        </Link>
                    );
                })}
            </div>
        </nav>
    );
}

export function documentsSectionFromView(
    view: 'roster' | 'signatures' | 'history',
): DocumentsModuleSection {
    if (view === 'signatures') {
        return 'requests';
    }

    if (view === 'history') {
        return 'activity';
    }

    return 'generate';
}

export function documentsUrlForView(
    view: 'roster' | 'signatures' | 'history',
): string {
    if (view === 'signatures') {
        return documentsRequests.url();
    }

    if (view === 'history') {
        return documentsActivity.url();
    }

    return documentsGenerate.url();
}
