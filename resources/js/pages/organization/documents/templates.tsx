import { Head, Link } from '@inertiajs/react';
import { ExternalLink, FilePenLine, Files, Sliders } from 'lucide-react';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { DocumentsModuleNav } from '@/features/organization/documents/documents-module-nav';
import { edit as applicationSettings } from '@/routes/application';
import { index as documentTypesSettings } from '@/routes/settings/master-data/document-types';

type Props = {
    section: 'templates';
    system_templates?: Array<{
        key: string;
        label: string;
        supports_esignature: boolean;
    }>;
    document_types?: Array<{
        key: string;
        label: string;
        supports_esignature: boolean;
    }>;
    can: {
        configure_placement: boolean;
        update_placement: boolean;
        manage_document_types: boolean;
    };
};

export default function DocumentTemplates({
    system_templates,
    document_types,
    can,
}: Props) {
    const templates = system_templates ?? document_types ?? [];

    return (
        <>
            <Head title="Document templates" />
            <Main>
                <DocumentsModuleNav active="templates" />
                <PageHeader
                    title="Templates"
                    description="Bridge to current template and e-signature configuration. The full template designer arrives in a later phase."
                />

                <div className="mb-6 rounded-xl border border-amber-500/30 bg-amber-500/5 px-4 py-3 text-sm text-amber-950 dark:text-amber-100">
                    <p className="font-medium">
                        Current / legacy configuration
                    </p>
                    <p className="mt-1 text-muted-foreground dark:text-amber-100/80">
                        Links below open existing Application settings and
                        Master Data screens. They are not the future Documents
                        template designer.
                    </p>
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <Card className="glass-card">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <FilePenLine className="h-4 w-4" />
                                Field placement
                                <Badge
                                    variant="outline"
                                    className="font-normal"
                                >
                                    Legacy
                                </Badge>
                            </CardTitle>
                            <CardDescription>
                                E-signature placement currently lives in
                                Application settings (
                                <code className="text-xs">
                                    settings.application.view
                                </code>
                                ).
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {templates
                                .filter((type) => type.supports_esignature)
                                .map((type) => (
                                    <div
                                        key={type.key}
                                        className="flex items-center justify-between gap-3 rounded-xl border border-border/50 px-4 py-3"
                                    >
                                        <div>
                                            <p className="text-sm font-medium">
                                                {type.label}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                Signature field placement
                                            </p>
                                        </div>
                                        {can.configure_placement ? (
                                            <Button
                                                asChild
                                                variant="outline"
                                                className="rounded-xl"
                                            >
                                                <Link
                                                    href={applicationSettings.url()}
                                                >
                                                    Open placement
                                                    <ExternalLink className="ml-2 h-3.5 w-3.5" />
                                                </Link>
                                            </Button>
                                        ) : (
                                            <p className="text-xs text-muted-foreground">
                                                Requires application settings
                                                view access
                                            </p>
                                        )}
                                    </div>
                                ))}
                            {templates.every(
                                (type) => !type.supports_esignature,
                            ) ? (
                                <p className="text-sm text-muted-foreground">
                                    No e-sign document types are registered yet.
                                </p>
                            ) : null}
                        </CardContent>
                    </Card>

                    <Card className="glass-card">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Sliders className="h-4 w-4" />
                                System / legacy generation templates
                                <Badge
                                    variant="outline"
                                    className="font-normal"
                                >
                                    Registry
                                </Badge>
                            </CardTitle>
                            <CardDescription>
                                System document generation definitions
                                registered in code (e.g. Salary Declaration /
                                Certificate).
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <ul className="space-y-2 text-sm">
                                {templates.map((type) => (
                                    <li
                                        key={type.key}
                                        className="flex items-center justify-between rounded-lg border border-border/40 px-3 py-2"
                                    >
                                        <span>{type.label}</span>
                                        <span className="text-xs text-muted-foreground">
                                            {type.supports_esignature
                                                ? 'E-sign'
                                                : 'Generate'}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>

                    <Card className="glass-card">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Files className="h-4 w-4" />
                                Document Types
                                <Badge
                                    variant="outline"
                                    className="font-normal"
                                >
                                    Master Data
                                </Badge>
                            </CardTitle>
                            <CardDescription>
                                Master-data document classifications used for
                                employee library uploads and tracking (
                                <code className="text-xs">
                                    settings.master-data.document-types.view
                                </code>
                                ).
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <p className="text-xs text-muted-foreground">
                                Document Types define classification categories
                                (e.g. Passport, Visa, Emirates ID), whereas
                                templates define generation layouts.
                            </p>
                            {can.manage_document_types ? (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="rounded-xl"
                                >
                                    <Link href={documentTypesSettings.url()}>
                                        Manage document types
                                        <ExternalLink className="ml-2 h-3.5 w-3.5" />
                                    </Link>
                                </Button>
                            ) : (
                                <p className="text-xs text-muted-foreground">
                                    Document type management requires
                                    master-data document-types view access.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </Main>
        </>
    );
}
