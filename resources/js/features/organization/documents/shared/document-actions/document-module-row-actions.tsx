import { Send } from 'lucide-react';
import { useState } from 'react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import { ManagementDocumentActions } from '@/features/organization/documents/shared/document-actions/management-actions';
import type { DocumentBrowseItem } from '@/features/organization/documents/shared/types';
import { ConfirmSendWhatsAppDocumentDialog } from '@/features/organization/documents/whatsapp-template/confirm-send-dialog';
import type { WhatsAppTemplateOption } from '@/features/organization/documents/whatsapp-template/types';
import type { PhoneCountryOption } from '@/lib/phone-with-dial-code';

type DocumentModuleRowActionsProps = {
    doc: DocumentBrowseItem;
    onPreview: () => void;
    canDownload?: boolean;
    canUpload?: boolean;
    canDelete?: boolean;
    onVersions?: () => void;
    onReplace?: () => void;
    onEdit?: () => void;
    onDelete?: () => void;
    canSendWhatsAppTemplate?: boolean;
    whatsappTemplates?: WhatsAppTemplateOption[];
    countries?: PhoneCountryOption[];
    employeeId?: number;
    employeeName?: string;
    employeePhone?: string | null;
    className?: string;
};

export function DocumentModuleRowActions({
    doc,
    onPreview,
    canDownload = false,
    canUpload = false,
    canDelete = false,
    onVersions,
    onReplace,
    onEdit,
    onDelete,
    canSendWhatsAppTemplate = false,
    whatsappTemplates = [],
    countries = [],
    employeeId,
    employeeName = '',
    employeePhone,
    className,
}: DocumentModuleRowActionsProps): ReactElement {
    const [whatsappDialogOpen, setWhatsappDialogOpen] = useState(false);
    const showWhatsApp =
        canSendWhatsAppTemplate &&
        employeeId !== undefined &&
        countries.length > 0;

    return (
        <>
            <div className="inline-flex items-center justify-end gap-0.5">
                <ManagementDocumentActions
                    documentId={doc.id}
                    canPreview={doc.can_preview}
                    fileUrl={doc.file_url}
                    onPreview={onPreview}
                    showDownload={canDownload}
                    showVersions={canUpload}
                    onVersions={onVersions}
                    showReplace={canUpload}
                    onReplace={onReplace}
                    showEdit={canUpload}
                    onEdit={onEdit}
                    showDelete={canDelete}
                    onDelete={onDelete}
                    className={className}
                />
                {showWhatsApp ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 rounded-lg text-muted-foreground hover:bg-accent hover:text-foreground dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-zinc-100"
                        title="Send via WhatsApp"
                        aria-label="Send via WhatsApp"
                        onClick={() => setWhatsappDialogOpen(true)}
                    >
                        <Send className="size-4" />
                    </Button>
                ) : null}
            </div>

            {showWhatsApp ? (
                <ConfirmSendWhatsAppDocumentDialog
                    open={whatsappDialogOpen}
                    onOpenChange={setWhatsappDialogOpen}
                    employeeId={employeeId}
                    employeeName={employeeName}
                    employeePhone={employeePhone}
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
