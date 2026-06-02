import { Download, ExternalLink, Eye, Send } from 'lucide-react';
import { useState } from 'react';

import { TableRowActions } from '@/components/table-row-actions';
import { ConfirmSendWhatsAppDocumentDialog } from '@/features/organization/documents/whatsapp-template/confirm-send-dialog';
import type { WhatsAppTemplateOption } from '@/features/organization/documents/whatsapp-template/types';
import { documents } from '@/routes/organization';
import type { DocumentBrowseItem } from '@/features/organization/documents/shared/types';

export function BrowseDocumentActions({
    doc,
    employeeId,
    employeePhone,
    onPreview,
    canDownload = false,
    canSendWhatsAppTemplate = false,
    whatsappTemplates = [],
}: {
    doc: DocumentBrowseItem;
    employeeId: number;
    employeePhone?: string | null;
    onPreview: (doc: DocumentBrowseItem) => void;
    canDownload?: boolean;
    canSendWhatsAppTemplate?: boolean;
    whatsappTemplates?: WhatsAppTemplateOption[];
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
                        href: documents.files.download.url({ document: doc.id }),
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
                employeePhone={employeePhone}
                documentId={doc.id}
                documentName={doc.document_name}
                templates={whatsappTemplates}
            />
        </>
    );
}
