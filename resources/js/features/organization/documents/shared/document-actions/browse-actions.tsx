import { Download, ExternalLink, Eye, Send } from 'lucide-react';
import { useState } from 'react';

import { TableRowActions } from '@/components/table-row-actions';
import type { DocumentBrowseItem } from '@/features/organization/documents/shared/types';
import { ConfirmSendWhatsAppDocumentDialog } from '@/features/organization/documents/whatsapp-template/confirm-send-dialog';
import type { WhatsAppTemplateOption } from '@/features/organization/documents/whatsapp-template/types';
import type { PhoneCountryOption } from '@/lib/phone-with-dial-code';
import { documents } from '@/routes/organization';

export function BrowseDocumentActions({
    doc,
    employeeId,
    employeeName,
    employeePhone,
    onPreview,
    canDownload = false,
    canSendWhatsAppTemplate = false,
    whatsappTemplates = [],
    countries,
}: {
    doc: DocumentBrowseItem;
    employeeId: number;
    employeeName: string;
    employeePhone?: string | null;
    onPreview: (doc: DocumentBrowseItem) => void;
    canDownload?: boolean;
    canSendWhatsAppTemplate?: boolean;
    whatsappTemplates?: WhatsAppTemplateOption[];
    countries: PhoneCountryOption[];
}) {
    const [whatsappDialogOpen, setWhatsappDialogOpen] = useState(false);

    return (
        <>
            <TableRowActions
                actions={[
                    {
                        label: 'View',
                        icon: Eye,
                        onClick: () => onPreview(doc),
                        hidden: !doc.can_preview,
                    },
                    {
                        label: 'Download',
                        icon: Download,
                        href: documents.files.download.url({
                            document: doc.id,
                        }),
                        hidden: !canDownload,
                    },
                    {
                        label: 'Send via WhatsApp',
                        icon: Send,
                        onClick: () => setWhatsappDialogOpen(true),
                        hidden: !canSendWhatsAppTemplate,
                    },
                    {
                        label: 'Open file',
                        icon: ExternalLink,
                        href: doc.file_url,
                        target: '_blank',
                        rel: 'noreferrer',
                    },
                ]}
            />

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
        </>
    );
}
