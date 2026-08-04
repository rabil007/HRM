import { Head, Link } from '@inertiajs/react';
import { ExternalLink, FilePenLine, Files } from 'lucide-react';
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
import { DocumentsModuleNav } from '@/features/organization/documents/documents-module-nav';

type Props = {
    section: 'templates';
    document_types: Array<{
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
    document_types,
    can,
}: Props) {
    return (
        <>
            <Head title="Document templates" />
            <Main>
                <DocumentsModuleNav active="templates" />
                <PageHeader
                    title="Templates"
                    description="Manage document generation templates and e-signature field placement. Full template designer screens arrive in a later phase."
                />

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card className="glass-card">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <FilePenLine className="h-4 w-4" />
                                Field placement
                            </CardTitle>
                            <CardDescription>
                                E-signature placement currently lives in
                                Application settings. It will move under
                                Templates → Field Placement without removing
                                existing placement data.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {document_types
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
                                                <Link href="/settings/application">
                                                    Open placement
                                                    <ExternalLink className="ml-2 h-3.5 w-3.5" />
                                                </Link>
                                            </Button>
                                        ) : (
                                            <p className="text-xs text-muted-foreground">
                                                Needs application settings
                                                access
                                            </p>
                                        )}
                                    </div>
                                ))}
                        </CardContent>
                    </Card>

                    <Card className="glass-card">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Files className="h-4 w-4" />
                                Document types
                            </CardTitle>
                            <CardDescription>
                                Master-data document types used by the employee
                                library and generation flows.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <ul className="space-y-2 text-sm">
                                {document_types.map((type) => (
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
                            {can.manage_document_types ? (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="rounded-xl"
                                >
                                    <Link href="/settings/master-data/document-types">
                                        Manage document types
                                        <ExternalLink className="ml-2 h-3.5 w-3.5" />
                                    </Link>
                                </Button>
                            ) : null}
                        </CardContent>
                    </Card>
                </div>
            </Main>
        </>
    );
}
