import { Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    FileStack,
    History,
    LayoutGrid,
    PenLine,
    Send,
} from 'lucide-react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { DocumentRequirementSummaryCards } from '@/features/organization/documents/document-requirement-summary-cards';
import { DocumentsModuleNav } from '@/features/organization/documents/documents-module-nav';
import { DocumentsSummaryCards } from '@/features/organization/documents/documents-summary-cards';
import type { ExpiryFilter } from '@/features/organization/documents/shared/document-expiry';
import type {
    DocumentExpirySummary,
    DocumentRequirementSummary,
    RequirementStatusFilter,
} from '@/features/organization/documents/shared/types';
import { documentsLibraryQuery } from '@/lib/documents-module-nav';
import {
    activity,
    generate,
    library,
    requests,
    templates,
} from '@/routes/organization/documents';

type AttentionItem = {
    key: string;
    label: string;
    count: number;
    query: Record<string, string>;
};

type OverviewSections = {
    overview: boolean;
    library: boolean;
    generate: boolean;
    requests: boolean;
    templates: boolean;
    activity: boolean;
};

const SHORTCUTS: {
    key: keyof OverviewSections;
    label: string;
    description: string;
    icon: typeof LayoutGrid;
    href: () => string;
}[] = [
    {
        key: 'library',
        label: 'Library',
        description: 'Browse folders, search, and compliance filters.',
        icon: LayoutGrid,
        href: () => library.url(),
    },
    {
        key: 'generate',
        label: 'Generate & Send',
        description: 'Create salary documents for a selected roster.',
        icon: Send,
        href: () => generate.url(),
    },
    {
        key: 'requests',
        label: 'Requests',
        description: 'Track outstanding signature requests.',
        icon: PenLine,
        href: () => requests.url(),
    },
    {
        key: 'templates',
        label: 'Templates',
        description: 'System generation templates and document types.',
        icon: FileStack,
        href: () => templates.url(),
    },
    {
        key: 'activity',
        label: 'Activity',
        description: 'Recent bulk generation history.',
        icon: History,
        href: () => activity.url(),
    },
];

function libraryHref(query: Record<string, string>): string {
    return Object.keys(query).length > 0
        ? library.url({ query })
        : library.url();
}

export function DocumentsOverviewContent({
    summary,
    requirementSummary,
    attention,
    sections,
}: {
    summary: DocumentExpirySummary;
    requirementSummary: DocumentRequirementSummary;
    attention: AttentionItem[];
    sections: OverviewSections;
}) {
    const visitLibraryExpiry = (expiry: ExpiryFilter) => {
        router.visit(libraryHref(documentsLibraryQuery({ expiry })));
    };

    const visitLibraryRequirement = (status: RequirementStatusFilter) => {
        router.visit(
            libraryHref(
                documentsLibraryQuery({
                    requirement_status: status || undefined,
                }),
            ),
        );
    };

    const visibleShortcuts = SHORTCUTS.filter(
        (shortcut) => sections[shortcut.key],
    );

    return (
        <Main>
            <PageHeader
                title="Overview"
                description="Operational document health for the active company. Open Library to browse, search, and filter."
            />

            <DocumentsModuleNav />

            <DocumentsSummaryCards
                summary={summary}
                activeExpiry={null}
                onSelect={visitLibraryExpiry}
                trailing={
                    <DocumentRequirementSummaryCards
                        summary={requirementSummary}
                        activeStatus=""
                        onSelect={visitLibraryRequirement}
                    />
                }
            />

            <p className="mb-6 text-sm text-muted-foreground">
                Required {requirementSummary.required}
                {' · '}
                Valid {requirementSummary.valid}
                {' · '}
                Expiring {requirementSummary.expiring}
                {' · '}
                Expired {requirementSummary.expired}
                {' · '}
                Missing {requirementSummary.missing}
            </p>

            {attention.length > 0 ? (
                <Card className="mb-6">
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <AlertTriangle className="h-4 w-4 text-muted-foreground" />
                            <CardTitle>Needs attention</CardTitle>
                        </div>
                        <CardDescription>
                            Open Library with the matching filter. Counts use
                            the current company document data.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {attention.map((item) => (
                            <div
                                key={item.key}
                                className="flex items-center justify-between gap-3 rounded-xl border border-border/60 px-3 py-2.5"
                            >
                                <div className="min-w-0">
                                    <p className="text-sm font-medium text-foreground">
                                        {item.label}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {item.count}{' '}
                                        {item.count === 1 ? 'item' : 'items'}
                                    </p>
                                </div>
                                <Button asChild variant="outline" size="sm">
                                    <Link href={libraryHref(item.query)}>
                                        Review in Library
                                    </Link>
                                </Button>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            ) : null}

            {visibleShortcuts.length > 0 ? (
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {visibleShortcuts.map((shortcut) => {
                        const Icon = shortcut.icon;

                        return (
                            <Card key={shortcut.key}>
                                <CardHeader>
                                    <div className="flex items-center gap-2">
                                        <Icon className="h-4 w-4 text-muted-foreground" />
                                        <CardTitle>{shortcut.label}</CardTitle>
                                    </div>
                                    <CardDescription>
                                        {shortcut.description}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <Button
                                        asChild
                                        variant="outline"
                                        className="w-full"
                                    >
                                        <Link href={shortcut.href()}>
                                            Open {shortcut.label}
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        );
                    })}
                </div>
            ) : null}
        </Main>
    );
}
