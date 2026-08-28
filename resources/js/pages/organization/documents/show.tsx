import { Head, Link } from '@inertiajs/react';
import { ClipboardCheck, FileSignature, History } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import type { RecentActivityItem } from '@/components/recent-activity-card';
import { RecentActivityCard } from '@/components/recent-activity-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DocumentsBreadcrumbs } from '@/features/organization/documents/documents-breadcrumbs';
import { DocumentShowHeaderActions } from '@/features/organization/documents/shared/document-actions/document-list-row-actions';
import { DocumentExpiryStatusCell } from '@/features/organization/documents/shared/document-expiry-display';
import { DocumentManagementDialogs } from '@/features/organization/documents/shared/document-management-dialogs';
import { DocumentPreviewPanel } from '@/features/organization/documents/shared/document-preview-panel';
import { DocumentVersionHistory } from '@/features/organization/documents/shared/document-version-history';
import type {
    DocumentShowItem,
    DocumentTypeOption,
    EmployeeSummary,
} from '@/features/organization/documents/shared/types';
import { ConfirmSendWhatsAppDocumentDialog } from '@/features/organization/documents/whatsapp-template/confirm-send-dialog';
import type { WhatsAppTemplateOption } from '@/features/organization/documents/whatsapp-template/types';
import { RequestApprovalDialog } from '@/features/organization/documents/workflow/request-approval-dialog';
import { RequestRecipientActionDialog } from '@/features/organization/documents/workflow/request-recipient-action-dialog';
import type {
    RecipientRequestPermissions,
    WorkflowAssigneeOption,
    WorkflowPresetSummary,
} from '@/features/organization/documents/workflow/types';
import { formatDisplayDate } from '@/lib/format-date';
import type { PhoneCountryOption } from '@/lib/phone-with-dial-code';
import { formatBytes } from '@/lib/utils';
import documentRoutes from '@/routes/organization/documents';
import { show as employeeShow } from '@/routes/organization/employees';

type Props = {
    document: DocumentShowItem;
    employee: EmployeeSummary;
    countries: PhoneCountryOption[];
    document_types: DocumentTypeOption[];
    can: {
        download: boolean;
        share: boolean;
        upload: boolean;
        delete: boolean;
        request_approval: boolean;
        whatsapp_template: boolean;
        whatsapp_templates: WhatsAppTemplateOption[];
    };
    workflow: {
        summary: {
            id: number;
            status: string;
            status_label: string;
            show_url: string;
        } | null;
        can_create: boolean;
        assignee_options: WorkflowAssigneeOption[];
        presets: WorkflowPresetSummary[];
    };
    recipient_request: {
        can: RecipientRequestPermissions;
        can_request_sign: boolean;
        can_request_acknowledge: boolean;
        sign_blocked_reason: string | null;
        acknowledge_blocked_reason: string | null;
    };
    back: {
        href: string;
        label: string;
    };
    recent_activity: RecentActivityItem[];
    can_view_audit: boolean;
};

function MetadataField({
    label,
    value,
}: {
    label: string;
    value: string;
}): ReactElement {
    return (
        <div className="flex items-start justify-between gap-4 border-b border-border/50 px-1 py-3 last:border-b-0">
            <span className="text-[10px] font-bold tracking-[0.18em] text-muted-foreground/70 uppercase">
                {label}
            </span>
            <span className="max-w-[60%] text-right text-sm font-medium">
                {value}
            </span>
        </div>
    );
}

export default function DocumentShow({
    document: doc,
    employee,
    countries,
    document_types,
    can,
    workflow,
    recipient_request,
    back,
    recent_activity,
    can_view_audit,
}: Props): ReactElement {
    const [editDoc, setEditDoc] = useState<DocumentShowItem | null>(null);
    const [replaceDoc, setReplaceDoc] = useState<DocumentShowItem | null>(null);
    const [deleteDocId, setDeleteDocId] = useState<number | null>(null);
    const [whatsappDialogOpen, setWhatsappDialogOpen] = useState(false);
    const [approvalDialogOpen, setApprovalDialogOpen] = useState(false);
    const [signDialogOpen, setSignDialogOpen] = useState(false);
    const [acknowledgeDialogOpen, setAcknowledgeDialogOpen] = useState(false);

    const pageTitle =
        doc.title || doc.document_name || doc.document_type_label || 'Document';
    const whatsappTemplates = can.whatsapp_templates ?? [];

    return (
        <>
            <Head title={`${pageTitle} — ${employee.name}`} />

            <Main>
                <DocumentsBreadcrumbs
                    items={[
                        {
                            title: 'Documents',
                            href: documentRoutes.library.url(),
                        },
                        {
                            title: employee.name,
                            href: documentRoutes.employee.url({
                                employee: employee.id,
                            }),
                        },
                        { title: pageTitle },
                    ]}
                />

                <DetailsHeader
                    kicker="Document"
                    title={pageTitle}
                    description={
                        <span className="inline-flex flex-wrap items-center gap-2">
                            <Link
                                href={employeeShow.url({
                                    employee: employee.id,
                                })}
                                className="font-medium text-foreground hover:underline"
                            >
                                {employee.name}
                            </Link>
                            <span className="text-muted-foreground">·</span>
                            <span>{employee.employee_no}</span>
                            {doc.current_version && doc.current_version > 1 ? (
                                <>
                                    <span className="text-muted-foreground">
                                        ·
                                    </span>
                                    <Badge
                                        variant="secondary"
                                        className="text-[10px] uppercase"
                                    >
                                        v{doc.current_version}
                                    </Badge>
                                </>
                            ) : null}
                        </span>
                    }
                    backHref={back.href}
                    backLabel={back.label}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            {workflow.can_create ? (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => setApprovalDialogOpen(true)}
                                >
                                    <ClipboardCheck className="mr-2 h-4 w-4" />
                                    Request approval
                                </Button>
                            ) : null}
                            {recipient_request.can_request_sign ? (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => setSignDialogOpen(true)}
                                >
                                    <FileSignature className="mr-2 h-4 w-4" />
                                    Request signature
                                </Button>
                            ) : null}
                            {recipient_request.can_request_acknowledge ? (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    onClick={() => setAcknowledgeDialogOpen(true)}
                                >
                                    <FileSignature className="mr-2 h-4 w-4" />
                                    Request acknowledgement
                                </Button>
                            ) : null}
                            <DocumentShowHeaderActions
                                documentId={doc.id}
                                fileUrl={doc.file_url}
                                showDownload={can.download}
                                showReplace={can.upload}
                                onReplace={() => setReplaceDoc(doc)}
                                showEdit={can.upload}
                                onEdit={() => setEditDoc(doc)}
                                showDelete={can.delete}
                                onDelete={() => setDeleteDocId(doc.id)}
                            />
                        </div>
                    }
                />

                <div className="grid gap-4 sm:gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
                    <Card className="order-first h-fit border-border/80 xl:order-last dark:border-white/10">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Details</CardTitle>
                        </CardHeader>
                        <CardContent className="pt-0">
                            <MetadataField
                                label="Type"
                                value={
                                    doc.document_type_label ??
                                    doc.document_type ??
                                    '—'
                                }
                            />
                            <MetadataField
                                label="Title"
                                value={doc.title?.trim() || '—'}
                            />
                            <MetadataField
                                label="Document no."
                                value={doc.document_number?.trim() || '—'}
                            />
                            <MetadataField
                                label="Issue date"
                                value={
                                    doc.issue_date
                                        ? formatDisplayDate(doc.issue_date)
                                        : '—'
                                }
                            />
                            <MetadataField
                                label="Expiry date"
                                value={
                                    doc.expiry_date
                                        ? formatDisplayDate(doc.expiry_date)
                                        : '—'
                                }
                            />
                            <div className="flex items-start justify-between gap-4 border-b border-border/50 px-1 py-3">
                                <span className="text-[10px] font-bold tracking-[0.18em] text-muted-foreground/70 uppercase">
                                    Status
                                </span>
                                <DocumentExpiryStatusCell
                                    status={doc.expiry_status}
                                />
                            </div>
                            <MetadataField
                                label="File size"
                                value={formatBytes(doc.size_bytes)}
                            />
                            <MetadataField
                                label="Uploaded by"
                                value={doc.uploaded_by || '—'}
                            />
                            <MetadataField
                                label="Uploaded"
                                value={
                                    doc.created_at
                                        ? formatDisplayDate(doc.created_at)
                                        : '—'
                                }
                            />
                            {doc.notes?.trim() ? (
                                <div className="px-1 py-3">
                                    <div className="mb-2 text-[10px] font-bold tracking-[0.18em] text-muted-foreground/70 uppercase">
                                        Notes
                                    </div>
                                    <p className="text-sm leading-relaxed text-muted-foreground">
                                        {doc.notes}
                                    </p>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>

                    {doc.provenance ? (
                        <Card className="border-border/80 dark:border-white/10">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    Document Provenance
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-0">
                                <MetadataField
                                    label="Source"
                                    value={doc.provenance.source}
                                />
                                <MetadataField
                                    label="Template"
                                    value={doc.provenance.template_name}
                                />
                                <MetadataField
                                    label="Version"
                                    value={doc.provenance.template_version}
                                />
                                <MetadataField
                                    label="Generated"
                                    value={doc.provenance.generated_at || '—'}
                                />
                                <MetadataField
                                    label="Generated by"
                                    value={doc.provenance.generated_by || '—'}
                                />
                                {workflow.summary ? (
                                    <div className="flex items-start justify-between gap-4 border-b border-border/50 px-1 py-3 last:border-b-0">
                                        <span className="text-[10px] font-bold tracking-[0.18em] text-muted-foreground/70 uppercase">
                                            Workflow
                                        </span>
                                        <span className="max-w-[60%] text-right text-sm font-medium">
                                            <Link
                                                href={workflow.summary.show_url}
                                                className="inline-flex items-center gap-2 hover:underline"
                                            >
                                                {workflow.summary.status_label}
                                                <Badge variant="secondary">
                                                    View request
                                                </Badge>
                                            </Link>
                                        </span>
                                    </div>
                                ) : null}
                            </CardContent>
                        </Card>
                    ) : null}

                    <div className="order-last min-w-0 space-y-4 sm:space-y-6 xl:order-first">
                        <Card className="border-border/80 dark:border-white/10">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">
                                    Preview
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <DocumentPreviewPanel
                                    document={{
                                        title: doc.title,
                                        document_type_label:
                                            doc.document_type_label,
                                        file_url: doc.file_url,
                                        mime_type: doc.mime_type,
                                        can_preview: doc.can_preview,
                                    }}
                                    className="h-[min(60vh,820px)] min-h-[260px] sm:min-h-[420px]"
                                />
                            </CardContent>
                        </Card>

                        <Card className="border-border/80 dark:border-white/10">
                            <CardHeader className="pb-3">
                                <div className="flex items-center gap-2">
                                    <History className="h-4 w-4 text-muted-foreground" />
                                    <CardTitle className="text-base">
                                        Version history
                                    </CardTitle>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <DocumentVersionHistory
                                    versions={doc.versions}
                                    showDownload={can.download}
                                />
                            </CardContent>
                        </Card>
                    </div>
                </div>

                {can_view_audit ? (
                    <RecentActivityCard
                        items={recent_activity}
                        description="Latest changes for this document."
                    />
                ) : null}
            </Main>

            <DocumentManagementDialogs
                employeeId={employee.id}
                editDoc={editDoc}
                onEditDocChange={setEditDoc}
                replaceDoc={replaceDoc}
                onReplaceDocChange={setReplaceDoc}
                deleteDocId={deleteDocId}
                onDeleteDocIdChange={setDeleteDocId}
                partialReloadKeys={['document']}
                deleteRedirectUrl={back.href}
                documentTypes={document_types}
            />

            {can.whatsapp_template ? (
                <ConfirmSendWhatsAppDocumentDialog
                    open={whatsappDialogOpen}
                    onOpenChange={setWhatsappDialogOpen}
                    employeeId={employee.id}
                    employeeName={employee.name}
                    employeePhone={employee.phone}
                    documentId={doc.id}
                    documentName={doc.document_name}
                    documentTypeLabel={doc.document_type}
                    templates={whatsappTemplates}
                    countries={countries}
                />
            ) : null}
            {workflow.can_create ? (
                <RequestApprovalDialog
                    open={approvalDialogOpen}
                    onOpenChange={setApprovalDialogOpen}
                    employeeId={employee.id}
                    documentId={doc.id}
                    assigneeOptions={workflow.assignee_options}
                    presets={workflow.presets}
                />
            ) : null}
            {recipient_request.can_request_sign ? (
                <RequestRecipientActionDialog
                    open={signDialogOpen}
                    onOpenChange={setSignDialogOpen}
                    employeeId={employee.id}
                    documentId={doc.id}
                    employeeName={employee.name}
                    documentTitle={pageTitle}
                    action="sign"
                />
            ) : null}
            {recipient_request.can_request_acknowledge ? (
                <RequestRecipientActionDialog
                    open={acknowledgeDialogOpen}
                    onOpenChange={setAcknowledgeDialogOpen}
                    employeeId={employee.id}
                    documentId={doc.id}
                    employeeName={employee.name}
                    documentTitle={pageTitle}
                    action="acknowledge"
                />
            ) : null}
        </>
    );
}
