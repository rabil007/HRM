import { Head, Link } from '@inertiajs/react';
import { History } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { DetailsHeader } from '@/components/details-header';
import { Main } from '@/components/layout/main';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { DocumentsBreadcrumbs } from '@/features/organization/documents/documents-breadcrumbs';
import { DocumentShowHeaderActions } from '@/features/organization/documents/shared/document-actions/document-list-row-actions';
import { DocumentExpiryStatusCell } from '@/features/organization/documents/shared/document-expiry-display';
import { DocumentManagementDialogs } from '@/features/organization/documents/shared/document-management-dialogs';
import { DocumentPreviewPanel } from '@/features/organization/documents/shared/document-preview-panel';
import type {
    DocumentShowItem,
    EmployeeSummary,
} from '@/features/organization/documents/shared/types';
import { DocumentVersionHistory } from '@/features/organization/documents/shared/document-version-history';
import { ConfirmSendWhatsAppDocumentDialog } from '@/features/organization/documents/whatsapp-template/confirm-send-dialog';
import type { WhatsAppTemplateOption } from '@/features/organization/documents/whatsapp-template/types';
import { formatDisplayDate } from '@/lib/format-date';
import type { PhoneCountryOption } from '@/lib/phone-with-dial-code';
import { formatBytes } from '@/lib/utils';
import { documents } from '@/routes/organization';
import { show as employeeShow } from '@/routes/organization/employees';

type Props = {
    document: DocumentShowItem;
    employee: EmployeeSummary;
    countries: PhoneCountryOption[];
    can: {
        download: boolean;
        share: boolean;
        upload: boolean;
        delete: boolean;
        whatsapp_template: boolean;
        whatsapp_templates: WhatsAppTemplateOption[];
    };
    back: {
        href: string;
        label: string;
    };
};

function MetadataField({ label, value }: { label: string; value: string }): ReactElement {
    return (
        <div className="flex items-start justify-between gap-4 border-b border-border/50 px-1 py-3 last:border-b-0">
            <span className="text-[10px] font-bold uppercase tracking-[0.18em] text-muted-foreground/70">
                {label}
            </span>
            <span className="max-w-[60%] text-right text-sm font-medium">{value}</span>
        </div>
    );
}

export default function DocumentShow({
    document: doc,
    employee,
    countries,
    can,
    back,
}: Props): ReactElement {
    const [editDoc, setEditDoc] = useState<DocumentShowItem | null>(null);
    const [replaceDoc, setReplaceDoc] = useState<DocumentShowItem | null>(null);
    const [deleteDocId, setDeleteDocId] = useState<number | null>(null);
    const [whatsappDialogOpen, setWhatsappDialogOpen] = useState(false);

    const pageTitle = doc.title || doc.document_name || doc.document_type_label || 'Document';
    const whatsappTemplates = can.whatsapp_templates ?? [];

    return (
        <>
            <Head title={`${pageTitle} — ${employee.name}`} />

            <Main>
                <DocumentsBreadcrumbs
                    items={[
                        { title: 'Documents', href: documents.url() },
                        {
                            title: employee.name,
                            href: documents.employee.url({ employee: employee.id }),
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
                                href={employeeShow.url({ employee: employee.id })}
                                className="font-medium text-foreground hover:underline"
                            >
                                {employee.name}
                            </Link>
                            <span className="text-muted-foreground">·</span>
                            <span>{employee.employee_no}</span>
                            {doc.current_version && doc.current_version > 1 ? (
                                <>
                                    <span className="text-muted-foreground">·</span>
                                    <Badge variant="secondary" className="text-[10px] uppercase">
                                        v{doc.current_version}
                                    </Badge>
                                </>
                            ) : null}
                        </span>
                    }
                    backHref={back.href}
                    backLabel={back.label}
                    actions={
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
                    }
                />

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_320px]">
                    <div className="min-w-0 space-y-6">
                        <Card className="border-border/80 dark:border-white/10">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">Preview</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <DocumentPreviewPanel
                                    document={{
                                        title: doc.title,
                                        document_type_label: doc.document_type_label,
                                        file_url: doc.file_url,
                                        mime_type: doc.mime_type,
                                        can_preview: doc.can_preview,
                                    }}
                                    className="h-[min(70vh,820px)] min-h-[420px]"
                                />
                            </CardContent>
                        </Card>

                        <Card className="border-border/80 dark:border-white/10">
                            <CardHeader className="pb-3">
                                <div className="flex items-center gap-2">
                                    <History className="h-4 w-4 text-muted-foreground" />
                                    <CardTitle className="text-base">Version history</CardTitle>
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

                    <Card className="h-fit border-border/80 dark:border-white/10">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-base">Details</CardTitle>
                        </CardHeader>
                        <CardContent className="pt-0">
                            <MetadataField
                                label="Type"
                                value={doc.document_type_label ?? doc.document_type ?? '—'}
                            />
                            <MetadataField label="Title" value={doc.title?.trim() || '—'} />
                            <MetadataField
                                label="Document no."
                                value={doc.document_number?.trim() || '—'}
                            />
                            <MetadataField
                                label="Issue date"
                                value={doc.issue_date ? formatDisplayDate(doc.issue_date) : '—'}
                            />
                            <MetadataField
                                label="Expiry date"
                                value={doc.expiry_date ? formatDisplayDate(doc.expiry_date) : '—'}
                            />
                            <div className="flex items-start justify-between gap-4 border-b border-border/50 px-1 py-3">
                                <span className="text-[10px] font-bold uppercase tracking-[0.18em] text-muted-foreground/70">
                                    Status
                                </span>
                                <DocumentExpiryStatusCell status={doc.expiry_status} />
                            </div>
                            <MetadataField label="File size" value={formatBytes(doc.size_bytes)} />
                            <MetadataField label="Uploaded by" value={doc.uploaded_by || '—'} />
                            <MetadataField
                                label="Uploaded"
                                value={doc.created_at ? formatDisplayDate(doc.created_at) : '—'}
                            />
                            {doc.notes?.trim() ? (
                                <div className="px-1 py-3">
                                    <div className="mb-2 text-[10px] font-bold uppercase tracking-[0.18em] text-muted-foreground/70">
                                        Notes
                                    </div>
                                    <p className="text-sm leading-relaxed text-muted-foreground">
                                        {doc.notes}
                                    </p>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>
                </div>
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
        </>
    );
}
