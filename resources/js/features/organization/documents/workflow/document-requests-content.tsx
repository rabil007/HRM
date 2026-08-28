import { router } from '@inertiajs/react';
import { ClipboardCheck, FilePenLine } from 'lucide-react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { BulkSignaturesTable } from '@/features/organization/documents/bulk/bulk-signatures-table';
import { DocumentsModuleNav } from '@/features/organization/documents/documents-module-nav';
import type { DocumentRequestsIndexProps } from '@/features/organization/documents/workflow/types';
import { WorkflowRequestsTable } from '@/features/organization/documents/workflow/workflow-requests-table';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { cn } from '@/lib/utils';
import documentRoutes from '@/routes/organization/documents';

function RequestsTabSwitcher({
    tab,
    canViewReview,
    canViewSignatures,
}: {
    tab: 'review' | 'signatures';
    canViewReview: boolean;
    canViewSignatures: boolean;
}) {
    return (
        <div className="inline-flex items-center gap-0.5 rounded-lg bg-muted/60 p-0.5">
            {canViewReview ? (
                <button
                    type="button"
                    onClick={() =>
                        router.get(documentRoutes.requests.url(), {
                            tab: 'review',
                        })
                    }
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                        tab === 'review'
                            ? 'bg-background text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    <ClipboardCheck className="h-3.5 w-3.5" />
                    Review &amp; Approval
                </button>
            ) : null}
            {canViewSignatures ? (
                <button
                    type="button"
                    onClick={() =>
                        router.get(documentRoutes.requests.url(), {
                            tab: 'signatures',
                        })
                    }
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                        tab === 'signatures'
                            ? 'bg-background text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    <FilePenLine className="h-3.5 w-3.5" />
                    Signature Requests
                </button>
            ) : null}
        </div>
    );
}

export function DocumentRequestsContent(props: DocumentRequestsIndexProps) {
    const {
        tab,
        can,
        filters,
        search: initialSearch,
        workflow_requests,
        pagination,
        signature_payload,
    } = props;

    const list = useServerPaginationFilters({
        url: documentRoutes.requests.url(),
        search: initialSearch,
        filters: {
            tab,
            status: String(filters.status ?? ''),
            action: String(filters.action ?? ''),
            assigned_to_me: filters.assigned_to_me ? '1' : '',
        },
        pagination,
    });

    return (
        <Main>
            <DocumentsModuleNav />
            <PageHeader
                title="Requests"
                description="Review and approval workflows plus legacy signature requests."
                right={
                    <RequestsTabSwitcher
                        tab={tab}
                        canViewReview={can.view}
                        canViewSignatures={can.view_signatures}
                    />
                }
            />

            {tab === 'review' && can.view ? (
                <div className="space-y-4">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                        <SearchBar
                            value={list.searchInput}
                            onChange={list.onSearchChange}
                            placeholder="Search employee, document, requester"
                            className="max-w-md"
                        />
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="space-y-2">
                                <Label>Status</Label>
                                <AppSelect
                                    value={String(filters.status ?? '')}
                                    onValueChange={(value) =>
                                        router.get(
                                            documentRoutes.requests.url(),
                                            {
                                                tab: 'review',
                                                search: list.searchInput,
                                                status:
                                                    value === '__all__'
                                                        ? ''
                                                        : value,
                                                action: String(
                                                    filters.action ?? '',
                                                ),
                                                assigned_to_me:
                                                    filters.assigned_to_me
                                                        ? '1'
                                                        : '',
                                            },
                                        )
                                    }
                                >
                                    <AppSelectItem value="__all__">
                                        All
                                    </AppSelectItem>
                                    <AppSelectItem value="pending">
                                        Pending
                                    </AppSelectItem>
                                    <AppSelectItem value="approved">
                                        Approved
                                    </AppSelectItem>
                                    <AppSelectItem value="rejected">
                                        Rejected
                                    </AppSelectItem>
                                    <AppSelectItem value="cancelled">
                                        Cancelled
                                    </AppSelectItem>
                                </AppSelect>
                            </div>
                            <div className="space-y-2">
                                <Label>Stage action</Label>
                                <AppSelect
                                    value={String(filters.action ?? '')}
                                    onValueChange={(value) =>
                                        router.get(
                                            documentRoutes.requests.url(),
                                            {
                                                tab: 'review',
                                                search: list.searchInput,
                                                status: String(
                                                    filters.status ?? '',
                                                ),
                                                action:
                                                    value === '__all__'
                                                        ? ''
                                                        : value,
                                                assigned_to_me:
                                                    filters.assigned_to_me
                                                        ? '1'
                                                        : '',
                                            },
                                        )
                                    }
                                >
                                    <AppSelectItem value="__all__">
                                        All
                                    </AppSelectItem>
                                    <AppSelectItem value="review">
                                        Review
                                    </AppSelectItem>
                                    <AppSelectItem value="approve">
                                        Approve
                                    </AppSelectItem>
                                </AppSelect>
                            </div>
                            <label className="flex items-center gap-2 pb-2 text-sm">
                                <Checkbox
                                    checked={Boolean(filters.assigned_to_me)}
                                    onCheckedChange={(checked) =>
                                        router.get(
                                            documentRoutes.requests.url(),
                                            {
                                                tab: 'review',
                                                search: list.searchInput,
                                                status: String(
                                                    filters.status ?? '',
                                                ),
                                                action: String(
                                                    filters.action ?? '',
                                                ),
                                                assigned_to_me: checked
                                                    ? '1'
                                                    : '',
                                            },
                                        )
                                    }
                                />
                                Assigned to me
                            </label>
                        </div>
                    </div>

                    <WorkflowRequestsTable requests={workflow_requests} />

                    <Pagination {...list.paginationProps} />
                </div>
            ) : null}

            {tab === 'signatures' &&
            can.view_signatures &&
            signature_payload ? (
                <BulkSignaturesTable
                    requests={signature_payload.signature_requests}
                    canReview={signature_payload.can.review_signatures}
                    canDownload={signature_payload.can.download}
                />
            ) : null}
        </Main>
    );
}
