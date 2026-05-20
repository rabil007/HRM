import { ExternalLink, Eye } from 'lucide-react';
import {
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
} from '@/components/data-table';
import { TableRowActions } from '@/components/table-row-actions';
import { Badge } from '@/components/ui/badge';
import { TableCell, TableRow } from '@/components/ui/table';
import { DocumentFileIcon } from '@/features/organization/documents/document-file-icon';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import type { DocumentBrowseItem } from './types';

export function EmployeeDocumentTableRow({
    doc,
    onPreview,
}: {
    doc: DocumentBrowseItem;
    onPreview: (doc: DocumentBrowseItem) => void;
}) {
    return (
        <TableRow className={dataTableBodyRowClass(false)}>
            <TableCell className="px-4 py-4 align-middle">
                <div className="flex min-w-0 items-center gap-3">
                    <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-white/5 bg-muted/30">
                        <DocumentFileIcon
                            mimeType={doc.mime_type}
                            fileName={doc.document_name}
                            className="h-5 w-5"
                        />
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-foreground">
                            {doc.document_name}
                        </p>
                        <p className="mt-0.5 truncate text-xs text-muted-foreground sm:hidden">
                            {doc.document_type}
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground md:hidden">
                            {formatDisplayDate(doc.uploaded_at)}
                        </p>
                    </div>
                </div>
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'hidden sm:table-cell')}>
                <Badge variant="outline" className="max-w-48 truncate font-normal">
                    {doc.document_type}
                </Badge>
            </TableCell>
            <TableCell
                className={cn(dataTableCellClass(), 'hidden whitespace-nowrap md:table-cell')}
            >
                {formatDisplayDate(doc.uploaded_at)}
            </TableCell>
            <TableCell className={dataTableActionsCellClass()}>
                <TableRowActions
                    actions={[
                        {
                            label: 'Preview',
                            icon: Eye,
                            onClick: () => onPreview(doc),
                            hidden: !doc.can_preview,
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
            </TableCell>
        </TableRow>
    );
}
