import { ExternalLink, Eye, FolderOpen } from 'lucide-react';
import {
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
} from '@/components/data-table';
import { TableRowActions } from '@/components/table-row-actions';
import { Badge } from '@/components/ui/badge';
import { TableCell, TableRow } from '@/components/ui/table';
import {
    expiryStatusClass,
    expiryStatusLabel,
    expiryStatusVariant,
} from '@/features/organization/documents/document-expiry';
import { DocumentFileIcon } from '@/features/organization/documents/document-file-icon';
import type { ComplianceDocumentItem } from '@/features/organization/documents/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';
import { documents } from '@/routes/organization';

export function DocumentComplianceTableRow({
    doc,
    onPreview,
}: {
    doc: ComplianceDocumentItem;
    onPreview: (doc: ComplianceDocumentItem) => void;
}) {
    return (
        <TableRow className={dataTableBodyRowClass(false)}>
            <TableCell className={cn(dataTableCellClass(), 'min-w-[140px]')}>
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-foreground">{doc.employee_name}</p>
                    <p className="truncate font-mono text-[11px] text-muted-foreground">{doc.employee_no}</p>
                </div>
            </TableCell>
            <TableCell className="px-4 py-4 align-middle">
                <div className="flex min-w-0 items-center gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-white/5 bg-muted/30">
                        <DocumentFileIcon
                            mimeType={doc.mime_type}
                            fileName={doc.document_name}
                            className="h-5 w-5"
                        />
                    </div>
                    <p className="truncate text-sm font-medium text-foreground">{doc.document_name}</p>
                </div>
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'hidden sm:table-cell')}>
                <Badge variant="outline" className="max-w-40 truncate font-normal">
                    {doc.document_type}
                </Badge>
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'hidden md:table-cell whitespace-nowrap')}>
                {formatDisplayDate(doc.expiry_date)}
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'hidden lg:table-cell')}>
                <span className="text-sm text-muted-foreground">{doc.expiry_label}</span>
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'hidden sm:table-cell')}>
                <Badge
                    variant={expiryStatusVariant(doc.expiry_status)}
                    className={cn('font-normal', expiryStatusClass(doc.expiry_status))}
                >
                    {expiryStatusLabel(doc.expiry_status)}
                </Badge>
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
                        {
                            label: 'Open folder',
                            icon: FolderOpen,
                            href: documents.employee.url({ employee: doc.employee_id }),
                        },
                    ]}
                />
            </TableCell>
        </TableRow>
    );
}
