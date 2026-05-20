import { Download, ExternalLink, Eye } from 'lucide-react';
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
import { formatDisplayDate } from '@/lib/format-date';
import { cn, formatBytes } from '@/lib/utils';
import { documents } from '@/routes/organization';
import type { DocumentBrowseItem } from './types';

function formatOptionalDate(value: string | null): string {
    return value ? formatDisplayDate(value) : '—';
}

export function EmployeeDocumentTableRow({
    doc,
    onPreview,
}: {
    doc: DocumentBrowseItem;
    onPreview: (doc: DocumentBrowseItem) => void;
}) {
    return (
        <TableRow className={dataTableBodyRowClass(false)}>
            <TableCell className="min-w-[240px] px-4 py-4 align-middle">
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
                        <p className="mt-1 md:hidden">
                            <DocumentExpiryBadge status={doc.expiry_status} className="text-[10px]" />
                        </p>
                    </div>
                </div>
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'hidden sm:table-cell')}>
                <Badge variant="outline" className="max-w-48 truncate border-white/10 font-normal">
                    {doc.document_type}
                </Badge>
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'hidden whitespace-nowrap md:table-cell')}>
                {formatOptionalDate(doc.issue_date)}
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'hidden whitespace-nowrap lg:table-cell')}>
                <div className="flex flex-col gap-1">
                    <span>{formatOptionalDate(doc.expiry_date)}</span>
                    {doc.expiry_date ? (
                        <span className={cn('text-xs', expiryRemainingClass(doc.expiry_status))}>
                            {doc.expiry_label}
                        </span>
                    ) : null}
                </div>
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'hidden whitespace-nowrap md:table-cell tabular-nums')}>
                {formatBytes(doc.size_bytes)}
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'hidden lg:table-cell')}>
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
                    ]}
                />
            </TableCell>
        </TableRow>
    );
}
