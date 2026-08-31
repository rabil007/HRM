import { Link, router } from '@inertiajs/react';
import {
    Bell,
    CheckCircle2,
    ClipboardCheck,
    FilePenLine,
    FileSignature,
    Settings2,
} from 'lucide-react';
import { useState } from 'react';
import { AppSelect, AppSelectItem } from '@/components/app-select';
import { Main } from '@/components/layout/main';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { SearchBar } from '@/components/search-bar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { BulkDocumentsContent } from '@/features/organization/documents/bulk/bulk-documents-content';
import type {
    BulkDocumentCounts,
    BulkDocumentsPageProps,
    BulkEmailFilter,
    BulkSignatureFilter,
} from '@/features/organization/documents/bulk/types';
import { RecipientReminderSettingsSheet } from '@/features/organization/documents/workflow/recipient-reminder-settings-sheet';
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

const TAB_ICONS = {
    review: ClipboardCheck,
    recipient: FileSignature,
    signatures: FilePenLine,
};

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
    const tabs = [
        {
            key: 'review' as const,
            label: 'Approvals',
            visible: canViewReview,
        },
        {
            key: 'recipient' as const,
            label: 'Employee Signing',
            visible: canViewRecipient,
        },
        {
            key: 'signatures' as const,
            label: 'Signature Requests',
            visible: canViewSignatures,
        },
    ].filter((t) => t.visible);

    if (tabs.length <= 1) {
        return null;
    }

    return (
        <div className="border-b border-border">
            <div className="flex items-center gap-0.5 px-1">
                {tabs.map(({ key, label }) => {
                    const Icon = TAB_ICONS[key];
                    const isActive = tab === key;

                    return (
                        <button
                            key={key}
                            type="button"
                            onClick={() =>
                                router.get(documentRoutes.requests.url(), {
                                    tab: key,
                                })
                            }
                            className={cn(
                                'relative flex items-center gap-2 px-4 py-3 text-sm font-medium transition-colors',
                                isActive
                                    ? 'text-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            <Icon
                                className={cn(
                                    'h-4 w-4 shrink-0',
                                    isActive
                                        ? 'text-primary'
                                        : 'text-muted-foreground',
                                )}
                            />
                            {label}
                            {isActive && (
                                <span className="absolute inset-x-0 bottom-0 h-0.5 rounded-full bg-primary" />
                            )}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

export function DocumentRequestsContent(props: DocumentRequestsIndexProps) {
    const {
        tab,
        can,
        preset_can,
        signing_preset_can,
        filters,
        search: initialSearch,
        workflow_requests,
        recipient_requests,
        recipient_automation = null,
        pagination,
        signature_payload,
    } = props;

    const [reminderSettingsOpen, setReminderSettingsOpen] = useState(false);

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
            <PageHeader
                title="Requests"
                description="Manage documents waiting for review, approval, signature or acknowledgement."
            />

            {/* Tab switcher — underline style full width */}
            <RequestsTabSwitcher
                tab={tab}
                canViewReview={can.view}
                canViewRecipient={
                    can.view_recipient_requests ||
                    can.respond_recipient_requests
                }
                canViewSignatures={can.view_signatures}
            />

            <div className="pt-6">
                {/* ── Approvals tab ─────────────────────────────────────── */}
                {tab === 'review' && can.view ? (
                    <div className="space-y-4">
                        {/* Unified filter bar */}
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            {/* Left: search */}
                            <SearchBar
                                value={list.searchInput}
                                onChange={list.onSearchChange}
                                placeholder="Search employee, document, requester"
                                className="max-w-sm"
                            />

                            {/* Right: filters + settings */}
                            <div className="flex flex-wrap items-center gap-2">
                                {/* Status select */}
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
                                        All statuses
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

                                {/* Stage action select */}
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
                                        All stages
                                    </AppSelectItem>
                                    <AppSelectItem value="review">
                                        Review
                                    </AppSelectItem>
                                    <AppSelectItem value="approve">
                                        Approve
                                    </AppSelectItem>
                                </AppSelect>

                                {/* Assigned to me toggle */}
                                <label
                                    className={cn(
                                        'flex h-9 cursor-pointer items-center gap-2 rounded-lg border px-3 text-sm transition-colors',
                                        filters.assigned_to_me
                                            ? 'border-primary bg-primary/5 font-medium text-foreground'
                                            : 'border-border bg-background text-muted-foreground hover:bg-muted',
                                    )}
                                >
                                    <Checkbox
                                        checked={Boolean(
                                            filters.assigned_to_me,
                                        )}
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

                                {/* Separator */}
                                {preset_can.view && (
                                    <>
                                        <div className="h-5 w-px bg-border" />
                                        <Button
                                            asChild
                                            variant="ghost"
                                            size="sm"
                                            className="gap-1.5 text-muted-foreground hover:text-foreground"
                                        >
                                            <Link
                                                href={documentRoutes.workflowPresets.url()}
                                            >
                                                <Settings2 className="h-3.5 w-3.5" />
                                                Approval Flows
                                            </Link>
                                        </Button>
                                    </>
                                )}
                            </div>
                        </div>

                        <WorkflowRequestsTable requests={workflow_requests} />

                        <Pagination {...list.paginationProps} />
                    </div>
                ) : null}

                {/* ── Employee Signing tab ───────────────────────────────── */}
                {tab === 'recipient' &&
                (can.view_recipient_requests ||
                    can.respond_recipient_requests) ? (
                    <div className="space-y-4">
                        {/* Unified filter bar */}
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <SearchBar
                                value={list.searchInput}
                                onChange={list.onSearchChange}
                                placeholder="Search employee, signatory, or document"
                                className="max-w-sm"
                            />

                            <div className="flex flex-wrap items-center gap-2">
                                {can.respond_recipient_requests && (
                                    <label
                                        className={cn(
                                            'flex h-9 cursor-pointer items-center gap-2 rounded-lg border px-3 text-sm transition-colors',
                                            filters.assigned_to_me
                                                ? 'border-primary bg-primary/5 font-medium text-foreground'
                                                : 'border-border bg-background text-muted-foreground hover:bg-muted',
                                        )}
                                    >
                                        <Checkbox
                                            checked={Boolean(
                                                filters.assigned_to_me,
                                            )}
                                            onCheckedChange={(checked) =>
                                                router.get(
                                                    documentRoutes.requests.url(),
                                                    {
                                                        tab: 'recipient',
                                                        search: list.searchInput,
                                                        status: String(
                                                            filters.status ??
                                                                '',
                                                        ),
                                                        action: String(
                                                            filters.action ??
                                                                '',
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
                                )}

                                {(signing_preset_can.view ||
                                    recipient_automation?.can_view) && (
                                    <div className="h-5 w-px bg-border" />
                                )}

                                {signing_preset_can.view && (
                                    <Button
                                        asChild
                                        variant="ghost"
                                        size="sm"
                                        className="gap-1.5 text-muted-foreground hover:text-foreground"
                                    >
                                        <Link
                                            href={documentRoutes.signingPresets.url()}
                                        >
                                            <Settings2 className="h-3.5 w-3.5" />
                                            Signing Flows
                                        </Link>
                                    </Button>
                                )}

                                {recipient_automation?.can_view && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        className="gap-1.5 text-muted-foreground hover:text-foreground"
                                        onClick={() =>
                                            setReminderSettingsOpen(true)
                                        }
                                    >
                                        <Bell className="h-3.5 w-3.5" />
                                        Reminder Settings
                                    </Button>
                                )}
                            </div>
                        </div>

                        <RecipientRequestsTable
                            requests={recipient_requests}
                            canRespond={can.respond_recipient_requests}
                            canCreate={can.create_recipient_requests}
                        />

                        <Pagination {...list.paginationProps} />

                        {recipient_automation?.can_view ? (
                            <RecipientReminderSettingsSheet
                                open={reminderSettingsOpen}
                                onOpenChange={setReminderSettingsOpen}
                                settings={recipient_automation}
                            />
                        ) : null}
                    </div>
                ) : null}

                {/* ── Signature Requests tab ────────────────────────────── */}
                {tab === 'signatures' &&
                can.view_signatures &&
                signature_payload ? (
                    <BulkDocumentsContent
                        {...mapSignaturePayloadToBulkProps(props)}
                    />
                ) : null}

                {/* ── No-permission fallback ────────────────────────────── */}
                {tab === 'review' && !can.view ? (
                    <div className="flex items-center gap-3 rounded-xl border border-border/60 bg-muted/30 px-5 py-4 text-sm text-muted-foreground">
                        <CheckCircle2 className="h-5 w-5 shrink-0 text-muted-foreground/50" />
                        You don&apos;t have permission to view approval
                        requests.
                    </div>
                ) : null}
            </div>
        </Main>
    );
}
