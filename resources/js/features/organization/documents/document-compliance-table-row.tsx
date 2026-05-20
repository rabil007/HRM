import { Download, ExternalLink, Eye, FolderOpen } from 'lucide-react';
import {
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
} from '@/components/data-table';
import { TableRowActions } from '@/components/table-row-actions';
import { Badge } from '@/components/ui/badge';
import { TableCell, TableRow } from '@/components/ui/table';
import { DocumentExpiryBadge } from '@/features/organization/documents/document-expiry-badge';
import { expiryRemainingClass } from '@/features/organization/documents/document-expiry';
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
                    <p className="truncate font-mono text-[11px] text-muted-foreground/75">{doc.employee_no}</p>
                </div>
            </TableCell>
            <TableCell className="min-w-[220px] px-4 py-4 align-middle">
                <div className="flex min-w-0 items-center gap-3.5">
                    <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-white/[0.06] bg-muted/25">
                        <DocumentFileIcon
                            mimeType={doc.mime_type}
                            fileName={doc.document_name}
                            className="h-5 w-5 text-foreground/80"
                        />
                    </div>
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-foreground">{doc.document_name}</p>
                        <p className="mt-0.5 truncate text-xs text-muted-foreground sm:hidden">
                            {doc.document_type}
                        </p>
                    </div>
                </div>
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'hidden sm:table-cell')}>
                <Badge variant="outline" className="max-w-44 truncate border-white/10 font-normal">
                    {doc.document_type}
                </Badge>
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'hidden whitespace-nowrap md:table-cell')}>
                {formatDisplayDate(doc.expiry_date)}
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'hidden lg:table-cell')}>
                <span className={cn('text-sm', expiryRemainingClass(doc.expiry_status))}>
                    {doc.expiry_label}
                </span>
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'hidden sm:table-cell')}>
                <DocumentExpiryBadge status={doc.expiry_status} />
            </TableCell>
            <TableCell className={dataTableActionsCellClass()}>
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
