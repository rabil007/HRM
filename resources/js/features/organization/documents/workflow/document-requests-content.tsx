import { Link, router } from '@inertiajs/react';
import {
    ClipboardCheck,
    FilePenLine,
    FileSignature,
    Settings2,
} from 'lucide-react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { BulkDocumentsContent } from '@/features/organization/documents/bulk/bulk-documents-content';
import type {
    BulkDocumentCounts,
    BulkDocumentsPageProps,
    BulkEmailFilter,
    BulkSignatureFilter,
} from '@/features/organization/documents/bulk/types';
import { DocumentsModuleNav } from '@/features/organization/documents/documents-module-nav';
import { RecipientRequestsTable } from '@/features/organization/documents/workflow/recipient-requests-table';
import type { DocumentRequestsIndexProps } from '@/features/organization/documents/workflow/types';
import { WorkflowRequestsTable } from '@/features/organization/documents/workflow/workflow-requests-table';
import { useServerPaginationFilters } from '@/hooks/use-server-pagination-filters';
import { cn } from '@/lib/utils';
import documentRoutes from '@/routes/organization/documents';

function mapSignaturePayloadToBulkProps(
    props: DocumentRequestsIndexProps,
): BulkDocumentsPageProps {
    const payload = props.signature_payload!;

    return {
        document_type_key: payload.document_type_key,
        document_type_options: payload.document_type_options,
        view: 'signatures',
        embedded_in_requests: true,
        filters: {
            department_id: String(props.filters.department_id ?? ''),
            position_id: String(props.filters.position_id ?? ''),
            company_visa_type_id: String(
                props.filters.company_visa_type_id ?? '',
            ),
            search: props.search,
        },
        search: props.search,
        counts: payload.counts as BulkDocumentCounts,
        employees: [],
        signature_requests: payload.signature_requests,
        activity: [],
        pagination: props.pagination,
        generation_filter: 'all',
        email_filter: payload.email_filter as BulkEmailFilter,
        signature_filter: payload.signature_filter as BulkSignatureFilter,
        departments: payload.departments,
        positions: payload.positions,
        company_visa_types: payload.company_visa_types,
        department_tree: payload.department_tree,
        department_tree_selected_id: payload.department_tree_selected_id,
        department_tree_selected_position_id:
            payload.department_tree_selected_position_id,
        company_name: payload.company_name,
        email_template: null,
        reminder_email_template: null,
        latest_run: payload.latest_run,
        latest_email_batch: payload.latest_email_batch,
        latest_signature_repair_run: payload.latest_signature_repair_run,
        can: payload.can,
    };
}

function RequestsTabSwitcher({
    tab,
    canViewReview,
    canViewRecipient,
    canViewSignatures,
}: {
    tab: 'review' | 'recipient' | 'signatures';
    canViewReview: boolean;
    canViewRecipient: boolean;
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
            {canViewRecipient ? (
                <button
                    type="button"
                    onClick={() =>
                        router.get(documentRoutes.requests.url(), {
                            tab: 'recipient',
                        })
                    }
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                        tab === 'recipient'
                            ? 'bg-background text-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    <FileSignature className="h-3.5 w-3.5" />
                    Signing &amp; Acknowledgement
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
        preset_can,
        filters,
        search: initialSearch,
        workflow_requests,
        recipient_requests,
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
                description="Review and approval, unified signing and acknowledgement, plus legacy bulk signature requests."
                right={
                    <RequestsTabSwitcher
                        tab={tab}
                        canViewReview={can.view}
                        canViewRecipient={
                            can.view_recipient_requests ||
                            can.respond_recipient_requests
                        }
                        canViewSignatures={can.view_signatures}
                    />
                }
            />

            {tab === 'review' && can.view ? (
                <div className="space-y-4">
                    {preset_can.view ? (
                        <div className="flex justify-end">
                            <Button asChild variant="outline" size="sm">
                                <Link
                                    href={documentRoutes.workflowPresets.url()}
                                >
                                    <Settings2 className="mr-2 h-4 w-4" />
                                    Workflow presets
                                </Link>
                            </Button>
                        </div>
                    ) : null}
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

            {tab === 'recipient' &&
            (can.view_recipient_requests || can.respond_recipient_requests) ? (
                <div className="space-y-4">
                    <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                        <SearchBar
                            value={list.searchInput}
                            onChange={list.onSearchChange}
                            placeholder="Search employee, signatory, or document"
                            className="max-w-md"
                        />
                        {can.respond_recipient_requests ? (
                            <label className="flex items-center gap-2 text-sm">
                                <Checkbox
                                    checked={Boolean(filters.assigned_to_me)}
                                    onCheckedChange={(checked) =>
                                        router.get(
                                            documentRoutes.requests.url(),
                                            {
                                                tab: 'recipient',
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
                        ) : null}
                    </div>
                    <RecipientRequestsTable
                        requests={recipient_requests}
                        canRespond={can.respond_recipient_requests}
                    />
                    <Pagination {...list.paginationProps} />
                </div>
            ) : null}

            {tab === 'signatures' &&
            can.view_signatures &&
            signature_payload ? (
                <BulkDocumentsContent
                    {...mapSignaturePayloadToBulkProps(props)}
                />
            ) : null}
        </Main>
    );
}
