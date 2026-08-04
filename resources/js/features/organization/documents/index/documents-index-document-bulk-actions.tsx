import { router, usePage } from '@inertiajs/react';
import {
    Download,
    FileStack,
    Loader2,
    Mail,
    MessageCircle,
    Send,
    Trash2,
} from 'lucide-react';
import { lazy, Suspense, useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { EmailDocumentsModal } from '@/features/organization/documents/email-send';
import type { EmailDocumentItem } from '@/features/organization/documents/email-send';
import type { EmailTemplateOption } from '@/features/organization/documents/email-send/email-template-types';
import type { MergeDocumentItem } from '@/features/organization/documents/pdf-merge/types';
import { DocumentsBulkToolbar } from '@/features/organization/documents/shared/bulk-toolbar';
import { ConfirmDeleteDocumentDialog } from '@/features/organization/documents/shared/confirm-delete-dialog';
import { downloadBulkZip } from '@/features/organization/documents/shared/download-bulk-zip';
import type {
    ComplianceDocumentItem,
    EmployeeSummary,
} from '@/features/organization/documents/shared/types';
import { ShareLinksModal } from '@/features/organization/documents/whatsapp-share';
import { ConfirmSendWhatsAppDocumentDialog } from '@/features/organization/documents/whatsapp-template/confirm-send-dialog';
import { resolveDefaultWhatsAppTemplate } from '@/features/organization/documents/whatsapp-template/types';
import type { WhatsAppTemplateOption } from '@/features/organization/documents/whatsapp-template/types';
import type { PhoneCountryOption } from '@/lib/phone-with-dial-code';
import { toast } from '@/lib/toast';
import documentRoutes from '@/routes/organization/documents';
import { shareLinks } from '@/routes/organization/documents/employee/files';

const PdfMergeModal = lazy(() =>
    import('@/features/organization/documents/pdf-merge/merge-modal').then(
        (module) => ({
            default: module.PdfMergeModal,
        }),
    ),
);

const SINGLE_EMPLOYEE_ACTION_MESSAGE =
    'Select documents from a single employee for this action.';

function resolveSingleEmployee(
    documents: ComplianceDocumentItem[],
): EmployeeSummary | null {
    if (documents.length === 0) {
        return null;
    }

    const employeeId = documents[0].employee_id;

    if (documents.some((document) => document.employee_id !== employeeId)) {
        return null;
    }

    return {
        id: employeeId,
        name: documents[0].employee_name,
        employee_no: documents[0].employee_no,
        email: documents[0].employee_email ?? null,
        phone: documents[0].employee_phone ?? null,
    };
}

export function DocumentsIndexDocumentBulkActions({
    selectedDocumentIds,
    selectedDocuments,
    onClear,
    can,
    countries,
}: {
    selectedDocumentIds: number[];
    selectedDocuments: ComplianceDocumentItem[];
    onClear: () => void;
    can: {
        download: boolean;
        share: boolean;
        delete: boolean;
        whatsapp_template: boolean;
        whatsapp_templates: WhatsAppTemplateOption[];
        email_templates: EmailTemplateOption[];
    };
    countries: PhoneCountryOption[];
}) {
    const { company_switcher_companies, current_company_id } = usePage()
        .props as unknown as {
        company_switcher_companies?: Array<{ id: number; name: string }>;
        current_company_id?: number | null;
    };

    const organizationName =
        company_switcher_companies?.find(
            (company) => company.id === current_company_id,
        )?.name ?? 'Organization';

    const whatsappTemplates = can.whatsapp_templates ?? [];
    const emailTemplates = can.email_templates ?? [];
    const defaultWhatsappTemplate =
        resolveDefaultWhatsAppTemplate(whatsappTemplates);

    const [isBulkDownloading, setIsBulkDownloading] = useState(false);
    const [mergeModalOpen, setMergeModalOpen] = useState(false);
    const [mergeDocuments, setMergeDocuments] = useState<MergeDocumentItem[]>(
        [],
    );
    const [emailModalOpen, setEmailModalOpen] = useState(false);
    const [emailDocuments, setEmailDocuments] = useState<EmailDocumentItem[]>(
        [],
    );
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [shareLinksModalOpen, setShareLinksModalOpen] = useState(false);
    const [actionEmployee, setActionEmployee] = useState<EmployeeSummary | null>(
        null,
    );
    const [whatsappTemplateDocument, setWhatsappTemplateDocument] = useState<{
        id: number;
        name: string;
        document_type: string;
    } | null>(null);

    const selectedCount = selectedDocumentIds.length;

    const singleEmployee = useMemo(
        () => resolveSingleEmployee(selectedDocuments),
        [selectedDocuments],
    );

    const requireSingleEmployee = (): EmployeeSummary | null => {
        if (!singleEmployee) {
            toast.error(SINGLE_EMPLOYEE_ACTION_MESSAGE);

            return null;
        }

        return singleEmployee;
    };

    const handleBulkDownload = async () => {
        if (selectedDocumentIds.length === 0) {
            return;
        }

        setIsBulkDownloading(true);

        try {
            await downloadBulkZip(documentRoutes.files.bulkDownload.url(), {
                document_ids: selectedDocumentIds,
            });
            onClear();
        } catch (error) {
            toast.error(
                error instanceof Error ? error.message : 'Download failed.',
            );
        } finally {
            setIsBulkDownloading(false);
        }
    };

    const handleMergePdfs = () => {
        const employee = requireSingleEmployee();

        if (!employee) {
            return;
        }

        if (selectedDocuments.length < 2) {
            toast.error('Select at least 2 PDF files to merge.');

            return;
        }

        if (
            selectedDocuments.some(
                (document) => document.mime_type !== 'application/pdf',
            )
        ) {
            toast.error('Only PDF documents can be merged.');

            return;
        }

        setActionEmployee(employee);
        setMergeDocuments(
            selectedDocuments.map((document) => ({
                id: document.id,
                document_name: document.document_name,
                file_url: document.file_url,
                size_bytes: document.size_bytes,
                mime_type: document.mime_type,
            })),
        );
        setMergeModalOpen(true);
    };

    const handleEmailDocuments = () => {
        const employee = requireSingleEmployee();

        if (!employee) {
            return;
        }

        if (selectedDocuments.length === 0) {
            toast.error('Select at least one document to email.');

            return;
        }

        setActionEmployee(employee);
        setEmailDocuments(
            selectedDocuments.map((document) => ({
                id: document.id,
                document_name: document.document_name,
                mime_type: document.mime_type,
                size_bytes: document.size_bytes,
            })),
        );
        setEmailModalOpen(true);
    };

    const handleWhatsAppShare = () => {
        const employee = requireSingleEmployee();

        if (!employee) {
            return;
        }

        if (selectedDocumentIds.length === 0) {
            toast.error('Select at least one document to share.');

            return;
        }

        setActionEmployee(employee);
        setShareLinksModalOpen(true);
    };

    const handleSendViaWhatsAppTemplate = () => {
        const employee = requireSingleEmployee();

        if (!employee) {
            return;
        }

        if (selectedDocumentIds.length !== 1) {
            toast.error('Select exactly one document to send via WhatsApp.');

            return;
        }

        const selectedDoc = selectedDocuments[0];

        if (!selectedDoc) {
            return;
        }

        setActionEmployee(employee);
        setWhatsappTemplateDocument({
            id: selectedDoc.id,
            name: selectedDoc.document_name,
            document_type: selectedDoc.document_type,
        });
    };

    const handleBulkDelete = () => {
        if (selectedDocumentIds.length === 0) {
            return;
        }

        setIsDeleting(true);

        router.delete(documentRoutes.files.bulkDestroy.url(), {
            data: { document_ids: selectedDocumentIds },
            preserveScroll: true,
            onSuccess: () => {
                onClear();
                setDeleteDialogOpen(false);
            },
            onError: () => {
                toast.error('Failed to delete selected documents.');
            },
            onFinish: () => {
                setIsDeleting(false);
            },
        });
    };

    return (
        <>
            <DocumentsBulkToolbar
                count={selectedCount}
                itemLabel="files"
                onClear={onClear}
                actions={
                    <>
                        {can.download ? (
                            <>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    className="rounded-lg"
                                    disabled={isBulkDownloading}
                                    onClick={handleBulkDownload}
                                >
                                    {isBulkDownloading ? (
                                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                    ) : (
                                        <Download className="mr-2 h-4 w-4" />
                                    )}
                                    Download
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    className="rounded-lg"
                                    onClick={handleMergePdfs}
                                >
                                    <FileStack className="mr-2 h-4 w-4" />
                                    Merge PDFs
                                </Button>
                            </>
                        ) : null}
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            className="rounded-lg"
                            onClick={handleEmailDocuments}
                        >
                            <Mail className="mr-2 h-4 w-4" />
                            Email
                        </Button>
                        {can.share ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="rounded-lg"
                                disabled={selectedCount === 0}
                                onClick={handleWhatsAppShare}
                            >
                                <MessageCircle className="mr-2 h-4 w-4" />
                                Share links
                            </Button>
                        ) : null}
                        {can.whatsapp_template ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="rounded-lg border-green-500/30 text-green-400 hover:bg-green-500/10 hover:text-green-300"
                                disabled={selectedCount !== 1}
                                onClick={handleSendViaWhatsAppTemplate}
                                title={
                                    selectedCount !== 1
                                        ? 'Select exactly one file to send via WhatsApp'
                                        : `Send PDF using the ${defaultWhatsappTemplate?.label ?? 'document delivery'} template`
                                }
                            >
                                <Send className="mr-2 h-4 w-4" />
                                Send via WhatsApp
                            </Button>
                        ) : null}
                        {can.delete ? (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="rounded-lg text-red-400/80 hover:bg-red-500/10 hover:text-red-400"
                                disabled={isDeleting}
                                onClick={() => setDeleteDialogOpen(true)}
                            >
                                <Trash2 className="mr-2 h-4 w-4" />
                                Delete
                            </Button>
                        ) : null}
                    </>
                }
            />

            <ConfirmDeleteDocumentDialog
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
                title="Delete selected documents"
                description={
                    <>
                        Are you sure you want to delete {selectedCount} selected{' '}
                        {selectedCount === 1 ? 'document' : 'documents'}? This
                        action cannot be undone.
                    </>
                }
                confirmLabel={isDeleting ? 'Deleting…' : 'Delete'}
                confirmDisabled={isDeleting}
                contentClassName="glass-card"
                cancelClassName="glass-card rounded-xl hover:bg-accent"
                confirmClassName="rounded-xl bg-red-600 hover:bg-red-600/90"
                onConfirm={handleBulkDelete}
            />

            {actionEmployee ? (
                <>
                    <EmailDocumentsModal
                        open={emailModalOpen}
                        onOpenChange={setEmailModalOpen}
                        employee={actionEmployee}
                        organizationName={organizationName}
                        documents={emailDocuments}
                        emailTemplates={emailTemplates}
                        onSendComplete={onClear}
                    />

                    <ShareLinksModal
                        open={shareLinksModalOpen}
                        onOpenChange={setShareLinksModalOpen}
                        employee={actionEmployee}
                        documentIds={selectedDocumentIds}
                        shareLinksUrl={shareLinks.url({
                            employee: actionEmployee.id,
                        })}
                        onComplete={onClear}
                    />

                    <ConfirmSendWhatsAppDocumentDialog
                        open={whatsappTemplateDocument !== null}
                        onOpenChange={(open) => {
                            if (!open) {
                                setWhatsappTemplateDocument(null);
                            }
                        }}
                        employeeId={actionEmployee.id}
                        employeeName={actionEmployee.name}
                        employeePhone={actionEmployee.phone}
                        documentId={whatsappTemplateDocument?.id ?? 0}
                        documentName={whatsappTemplateDocument?.name ?? ''}
                        documentTypeLabel={
                            whatsappTemplateDocument?.document_type
                        }
                        templates={whatsappTemplates}
                        countries={countries}
                        onSendComplete={onClear}
                    />

                    {mergeModalOpen ? (
                        <Suspense fallback={null}>
                            <PdfMergeModal
                                open={mergeModalOpen}
                                onOpenChange={setMergeModalOpen}
                                employee={actionEmployee}
                                documents={mergeDocuments}
                                onMergeComplete={onClear}
                            />
                        </Suspense>
                    ) : null}
                </>
            ) : null}
        </>
    );
}
