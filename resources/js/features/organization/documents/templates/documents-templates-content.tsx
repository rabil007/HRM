import { Link } from '@inertiajs/react';
import { FileStack, FileText, PenLine } from 'lucide-react';
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
import { generate } from '@/routes/organization/documents';
import { index as documentTypesIndex } from '@/routes/settings/master-data/document-types';

type SystemTemplate = {
    key: string;
    label: string;
    supports_esignature: boolean;
};

export function DocumentsTemplatesContent({
    systemTemplates,
    can,
}: {
    systemTemplates: SystemTemplate[];
    can: {
        document_types: boolean;
        signature_placement: boolean;
    };
}) {
    return (
        <Main>
            <PageHeader
                title="Templates"
                description="System generation templates, document types, and signature placement stay on their current implementations."
            />

            <DocumentsModuleNav />

            <div className="grid gap-4 lg:grid-cols-3">
                {systemTemplates.length > 0 ? (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <FileStack className="h-4 w-4 text-muted-foreground" />
                                <CardTitle>
                                    System generation templates
                                </CardTitle>
                            </div>
                            <CardDescription>
                                Legacy system renderers used by Generate &amp;
                                Send. Layout is not editable here.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {systemTemplates.map((template) => (
                                <div
                                    key={template.key}
                                    className="flex items-start justify-between gap-3 rounded-xl border border-border/60 px-3 py-2.5"
                                >
                                    <div className="min-w-0">
                                        <p className="text-sm font-medium text-foreground">
                                            {template.label}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Protected salary PDF renderer
                                        </p>
                                    </div>
                                    <Badge variant="secondary">System</Badge>
                                </div>
                            ))}
                            <Button
                                asChild
                                variant="outline"
                                className="w-full"
                            >
                                <Link href={generate.url()}>
                                    Open Generate &amp; Send
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : null}

                {can.document_types ? (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <FileText className="h-4 w-4 text-muted-foreground" />
                                <CardTitle>Document Types</CardTitle>
                            </div>
                            <CardDescription>
                                Classification and required-document compliance
                                configuration. These are not generation
                                templates.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button
                                asChild
                                variant="outline"
                                className="w-full"
                            >
                                <Link href={documentTypesIndex.url()}>
                                    Open Document Types
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : null}

                {can.signature_placement ? (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <PenLine className="h-4 w-4 text-muted-foreground" />
                                <CardTitle>Signature placement</CardTitle>
                            </div>
                            <CardDescription>
                                Salary Declaration signature and date placement
                                remains in Application settings.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button
                                asChild
                                variant="outline"
                                className="w-full"
                            >
                                <Link
                                    href={applicationSettings.url({
                                        query: { tab: 'esign' },
                                    })}
                                >
                                    Open signature placement
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : null}
            </div>
        </Main>
    );
}
