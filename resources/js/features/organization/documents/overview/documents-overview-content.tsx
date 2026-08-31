import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    FileStack,
    Send,
    SlidersHorizontal,
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
import type {
    OverviewAttentionItem,
    OverviewComplianceType,
    OverviewSections,
} from '@/features/organization/documents/overview/types';
import type {
    DocumentExpirySummary,
    DocumentRequirementSummary,
} from '@/features/organization/documents/shared/types';
import { documentsOverviewTypeViewQuery } from '@/lib/documents-module-nav';
import { cn } from '@/lib/utils';
import {
    configuration,
    generate,
    library,
    requests,
} from '@/routes/organization/documents';

function attentionHref(item: OverviewAttentionItem): string {
    if (item.destination === 'requests') {
        return requests.url({ query: item.query });
    }

    return library.url({ query: item.query });
}

function typeViewHref(row: OverviewComplianceType): string {
    return library.url({ query: documentsOverviewTypeViewQuery(row) });
}

function typeConfigureHref(row: OverviewComplianceType): string {
    return configuration.url({
        query: { edit: String(row.document_type_id) },
    });
}

function attentionTone(key: string): string {
    if (key === 'expired' || key === 'missing') {
        return 'border-rose-500/20 bg-rose-500/5';
    }

    if (key === 'expiring_7') {
        return 'border-amber-500/20 bg-amber-500/5';
    }

    return 'border-sky-500/20 bg-sky-500/5';
}

function attentionCountTone(key: string): string {
    if (key === 'expired' || key === 'missing') {
        return 'text-rose-700 dark:text-rose-300';
    }

    if (key === 'expiring_7') {
        return 'text-amber-700 dark:text-amber-300';
    }

    return 'text-sky-700 dark:text-sky-300';
}

export function DocumentsOverviewContent({
    summary,
    requirementSummary,
    attention,
    complianceTypes,
    sections,
}: {
    summary: DocumentExpirySummary;
    requirementSummary: DocumentRequirementSummary;
    attention: OverviewAttentionItem[];
    complianceTypes: OverviewComplianceType[];
    sections: OverviewSections;
}) {
    const visibleComplianceTypes = complianceTypes.filter(
        (row) => row.missing + row.expiring + row.expired > 0,
    );

    return (
        <Main>
            <PageHeader
                title="Overview"
                description="What needs attention now, who is affected, and the next action to take."
            />

            {attention.length > 0 ? (
                <section
                    className="mb-6"
                    aria-labelledby="needs-attention-heading"
                >
                    <div className="mb-3 flex items-center gap-2">
                        <AlertTriangle className="h-4 w-4 text-amber-600 dark:text-amber-400" />
                        <h2
                            id="needs-attention-heading"
                            className="text-sm font-semibold text-foreground"
                        >
                            Needs Attention
                        </h2>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {attention.map((item) => (
                            <Card
                                key={item.key}
                                className={cn(
                                    'border shadow-none',
                                    attentionTone(item.key),
                                )}
                            >
                                <CardHeader className="pb-2">
                                    <CardDescription>
                                        {item.label}
                                    </CardDescription>
                                    <CardTitle
                                        className={cn(
                                            'text-2xl tabular-nums',
                                            attentionCountTone(item.key),
                                        )}
                                    >
                                        {item.count}
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <Button
                                        asChild
                                        variant="outline"
                                        size="sm"
                                        className="w-full bg-background/70"
                                    >
                                        <Link href={attentionHref(item)}>
                                            {item.action}
                                        </Link>
                                    </Button>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                </section>
            ) : (
                <Card className="mb-6 border-border/70 shadow-none">
                    <CardHeader>
                        <div className="flex items-start gap-3">
                            <CheckCircle2 className="mt-0.5 h-4 w-4 text-muted-foreground" />
                            <div>
                                <CardTitle>No urgent document issues</CardTitle>
                                <CardDescription>
                                    All required documents and current requests
                                    are up to date.
                                </CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                </Card>
            )}

            {visibleComplianceTypes.length > 0 ? (
                <section
                    className="mb-6"
                    aria-labelledby="document-compliance-heading"
                >
                    <div className="mb-3">
                        <h2
                            id="document-compliance-heading"
                            className="text-sm font-semibold text-foreground"
                        >
                            Document Compliance
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            Document types currently driving missing or expiry
                            problems.
                        </p>
                    </div>
                    <div className="space-y-2 md:hidden">
                        {visibleComplianceTypes.map((row) => (
                            <Card
                                key={row.document_type_id}
                                className="shadow-none"
                            >
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-sm">
                                        {row.title}
                                    </CardTitle>
                                    <CardDescription>
                                        Missing {row.missing}
                                        {' · '}
                                        Expiring {row.expiring}
                                        {' · '}
                                        Expired {row.expired}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-wrap gap-2">
                                    <Button asChild variant="outline" size="sm">
                                        <Link href={typeViewHref(row)}>
                                            View
                                        </Link>
                                    </Button>
                                    {sections.configuration ? (
                                        <Button
                                            asChild
                                            variant="ghost"
                                            size="sm"
                                        >
                                            <Link href={typeConfigureHref(row)}>
                                                Configure
                                            </Link>
                                        </Button>
                                    ) : null}
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                    <div className="hidden overflow-hidden rounded-xl border border-border/70 md:block">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/40 text-left text-xs tracking-wide text-muted-foreground uppercase">
                                <tr>
                                    <th className="px-4 py-2.5 font-medium">
                                        Document Type
                                    </th>
                                    <th className="px-4 py-2.5 font-medium">
                                        Missing
                                    </th>
                                    <th className="px-4 py-2.5 font-medium">
                                        Expiring
                                    </th>
                                    <th className="px-4 py-2.5 font-medium">
                                        Expired
                                    </th>
                                    <th className="px-4 py-2.5 text-right font-medium">
                                        Action
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {visibleComplianceTypes.map((row) => (
                                    <tr
                                        key={row.document_type_id}
                                        className="border-t border-border/60"
                                    >
                                        <td className="px-4 py-2.5 font-medium text-foreground">
                                            {row.title}
                                        </td>
                                        <td className="px-4 py-2.5 tabular-nums">
                                            {row.missing}
                                        </td>
                                        <td className="px-4 py-2.5 tabular-nums">
                                            {row.expiring}
                                        </td>
                                        <td className="px-4 py-2.5 tabular-nums">
                                            {row.expired}
                                        </td>
                                        <td className="px-4 py-2.5">
                                            <div className="flex justify-end gap-2">
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                    size="sm"
                                                >
                                                    <Link
                                                        href={typeViewHref(row)}
                                                    >
                                                        View
                                                    </Link>
                                                </Button>
                                                {sections.configuration ? (
                                                    <Button
                                                        asChild
                                                        variant="ghost"
                                                        size="sm"
                                                    >
                                                        <Link
                                                            href={typeConfigureHref(
                                                                row,
                                                            )}
                                                        >
                                                            Configure
                                                        </Link>
                                                    </Button>
                                                ) : null}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            ) : null}

            <p className="mb-6 text-sm text-muted-foreground">
                {summary.total_documents} documents
                {' · '}
                Required {requirementSummary.required}
                {' · '}
                Valid {requirementSummary.valid}
            </p>

            <div className="flex flex-wrap gap-2">
                {sections.library ? (
                    <Button asChild variant="outline" size="sm">
                        <Link href={library.url()}>
                            <FileStack className="mr-2 h-4 w-4" />
                            Open Library
                        </Link>
                    </Button>
                ) : null}
                {sections.generate ? (
                    <Button asChild variant="outline" size="sm">
                        <Link href={generate.url()}>
                            <Send className="mr-2 h-4 w-4" />
                            Generate & Send
                        </Link>
                    </Button>
                ) : null}
                {sections.configuration ? (
                    <Button asChild variant="outline" size="sm">
                        <Link href={configuration.url()}>
                            <SlidersHorizontal className="mr-2 h-4 w-4" />
                            Manage Document Types
                        </Link>
                    </Button>
                ) : null}
            </div>
        </Main>
    );
}
