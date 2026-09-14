import { Head, Link } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { DocumentWorkspace } from '@/features/organization/entity-documents';
import type {
    EntityDocument,
    EntityDocumentPermissions,
    EntityDocumentSummary,
    EntityDocumentType,
} from '@/features/organization/entity-documents';
import {
    bulkStore,
    destroy,
    index as branchDocumentsIndex,
    replace,
    store,
    update,
} from '@/routes/organization/branches/documents';
import { index as branchDocumentVersionsIndex } from '@/routes/organization/branches/documents/versions';
import type { PaginationMeta } from '@/types/pagination';

export type BranchDocumentBranch = {
    id: number;
    name: string;
    code: string | null;
    city: string | null;
    country: string | null;
    is_headquarters: boolean;
};

export type BranchDocumentCompany = {
    id: number;
    name: string;
    logo_url: string | null;
};

export type BranchDocumentsPageProps = {
    company: BranchDocumentCompany;
    branch: BranchDocumentBranch;
    documents: EntityDocument[];
    pagination: PaginationMeta;
    filters: {
        search: string;
        document_type: number | null;
        expiry_status: string;
    };
    summary: EntityDocumentSummary;
    document_types: EntityDocumentType[];
    can: EntityDocumentPermissions;
};

export default function BranchDocumentsPage({
    company,
    branch,
    documents,
    pagination,
    filters,
    summary,
    document_types,
    can,
}: BranchDocumentsPageProps) {
    const routes = {
        pageUrl: branchDocumentsIndex.url(branch.id),
        store: store.url(branch.id),
        bulkStore: bulkStore.url(branch.id),
        update: (documentId: number) => update.url([branch.id, documentId]),
        destroy: (documentId: number) => destroy.url([branch.id, documentId]),
        replace: (documentId: number) => replace.url([branch.id, documentId]),
        versionsIndex: (documentId: number) =>
            branchDocumentVersionsIndex.url([branch.id, documentId]),
    };

    return (
        <>
            <Head title={`${branch.name} Documents • OMS-HRM`} />
            <DocumentWorkspace
                owner={{
                    id: branch.id,
                    name: branch.name,
                    subtitle: [branch.city, branch.country]
                        .filter(Boolean)
                        .join(', '),
                }}
                ownerType="branch"
                pageUrl={routes.pageUrl}
                backHref={`/organization/branches/${branch.id}`}
                backLabel="Branch details"
                title={`${branch.name} documents`}
                description="Branch compliance files, metadata, expiry tracking, and version history."
                routes={routes}
                permissions={can}
                documents={documents}
                pagination={pagination}
                filters={filters}
                summary={summary}
                documentTypes={document_types}
                emptyTitle="No branch documents found."
                headerActionsExtra={
                    can.manage_notifications ? (
                        <Button
                            variant="outline"
                            className="h-11 rounded-xl"
                            asChild
                        >
                            <Link
                                href={`/organization/companies/${company.id}/documents`}
                            >
                                <Bell className="mr-2 h-4 w-4" />
                                Expiry notifications
                            </Link>
                        </Button>
                    ) : null
                }
                bannerNotice={
                    <div className="mb-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-border/70 bg-muted/30 px-5 py-3.5 text-xs text-muted-foreground dark:border-white/5 dark:bg-white/5">
                        <div className="flex items-center gap-2.5">
                            <Bell className="h-4 w-4 shrink-0 text-muted-foreground/80" />
                            <span>
                                Expiry notifications are managed at company
                                level for all company and branch documents.
                            </span>
                        </div>
                        {can.manage_notifications ? (
                            <Link
                                href={`/organization/companies/${company.id}/documents`}
                                className="font-semibold text-primary hover:underline"
                            >
                                Manage company notifications →
                            </Link>
                        ) : null}
                    </div>
                }
            />
        </>
    );
}
