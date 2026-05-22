import { Download, ExternalLink, Eye } from 'lucide-react';
import { TableRowActions } from '@/components/table-row-actions';
import { documents } from '@/routes/organization';
import type { DocumentBrowseItem } from './types';

export function BrowseDocumentActions({
    doc,
    onPreview,
    canDownload = false,
}: {
    doc: DocumentBrowseItem;
    onPreview: (doc: DocumentBrowseItem) => void;
    canDownload?: boolean;
}) {
    return (
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
                    label: 'Open file',
                    icon: ExternalLink,
                    href: doc.file_url,
                    target: '_blank',
                    rel: 'noreferrer',
                },
            ]}
        />
    );
}
