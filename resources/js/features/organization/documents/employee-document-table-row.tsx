import {
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { TableCell, TableRow } from '@/components/ui/table';
import { BrowseDocumentActions } from '@/features/organization/documents/shared/document-actions/browse-actions';
import { DocumentExpiryBadge } from '@/features/organization/documents/shared/document-expiry-badge';
import { DocumentExpiryDisplay, DocumentExpiryStatusCell } from '@/features/organization/documents/shared/document-expiry-display';
import { DocumentFileIcon } from '@/features/organization/documents/shared/document-file-icon';
import { DocumentUploadedDisplay } from '@/features/organization/documents/shared/document-uploaded-display';
import { formatDisplayDate } from '@/lib/format-date';
import { cn, formatBytes } from '@/lib/utils';
import type { DocumentBrowseItem } from './types';

function formatOptionalDate(value: string | null): string {
    return value ? formatDisplayDate(value) : '—';
}

export function EmployeeDocumentTableRow({
    doc,
    onPreview,
    canDownload = false,
    selected = false,
    onSelectedChange,
    selectionMode = false,
}: {
    doc: DocumentBrowseItem;
    onPreview: (doc: DocumentBrowseItem) => void;
    canDownload?: boolean;
    selected?: boolean;
    onSelectedChange?: (selected: boolean) => void;
    selectionMode?: boolean;
}) {
    return (
        <TableRow className={cn(dataTableBodyRowClass(false), selected && 'bg-primary/5')}>
            {selectionMode ? (
                <TableCell className="w-10 px-3 py-4 align-middle">
                    <Checkbox
                        checked={selected}
                        onCheckedChange={(value) => onSelectedChange?.(value === true)}
                        aria-label={`Select ${doc.document_name}`}
                    />
                </TableCell>
            ) : null}
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
            <TableCell
                className={cn(
                    dataTableCellClass(),
                    'hidden max-w-[160px] truncate font-mono text-xs md:table-cell',
                )}
            >
                {doc.document_number?.trim() || '—'}
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'hidden whitespace-nowrap md:table-cell')}>
                {formatOptionalDate(doc.issue_date)}
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'hidden whitespace-nowrap lg:table-cell')}>
                <DocumentExpiryDisplay doc={doc} />
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'hidden whitespace-nowrap md:table-cell tabular-nums')}>
                {formatBytes(doc.size_bytes)}
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'hidden whitespace-nowrap lg:table-cell')}>
                <DocumentExpiryStatusCell status={doc.expiry_status} />
            </TableCell>
            <TableCell className={cn(dataTableCellClass(), 'hidden min-w-[120px] xl:table-cell')}>
                <DocumentUploadedDisplay doc={doc} />
            </TableCell>
            <TableCell className={dataTableActionsCellClass()}>
                <BrowseDocumentActions doc={doc} onPreview={onPreview} canDownload={canDownload} />
            </TableCell>
        </TableRow>
    );
}
