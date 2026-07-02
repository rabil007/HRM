import { router } from '@inertiajs/react';
import {
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { TableCell, TableRow } from '@/components/ui/table';
import { expiryRemainingClass } from '@/features/organization/documents/document-expiry';
import { DocumentExpiryBadge } from '@/features/organization/documents/document-expiry-badge';
import { DocumentModuleRowActions } from '@/features/organization/documents/shared/document-actions/document-module-row-actions';
import { DocumentFileIcon } from '@/features/organization/documents/shared/document-file-icon';
import type { ComplianceDocumentItem } from '@/features/organization/documents/shared/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn } from '@/lib/utils';

export function DocumentComplianceTableRow({
    doc,
    viewHref,
    canDownload = false,
    canUpload = false,
    canDelete = false,
    onEdit,
    onReplace,
    onDelete,
}: {
    doc: ComplianceDocumentItem;
    viewHref: string;
    canDownload?: boolean;
    canUpload?: boolean;
    canDelete?: boolean;
    onEdit?: (doc: ComplianceDocumentItem) => void;
    onReplace?: (doc: ComplianceDocumentItem) => void;
    onDelete?: (doc: ComplianceDocumentItem) => void;
}) {
    return (
        <TableRow
            className={cn(dataTableBodyRowClass(false), 'cursor-pointer')}
            onClick={() => router.visit(viewHref)}
        >
            <TableCell className={cn(dataTableCellClass(), 'min-w-[140px]')}>
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-foreground">
                        {doc.employee_name}
                    </p>
                    <p className="truncate font-mono text-[11px] text-muted-foreground/75">
                        {doc.employee_no}
                    </p>
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
                        <p className="truncate text-sm leading-snug font-semibold text-foreground">
                            {doc.document_name}
                        </p>
                        {doc.document_number?.trim() ? (
                            <p className="mt-0.5 truncate font-mono text-[11px] text-muted-foreground md:hidden">
                                {doc.document_number}
                            </p>
                        ) : null}
                        <p className="mt-0.5 truncate text-xs text-muted-foreground sm:hidden">
                            {doc.document_type}
                        </p>
                    </div>
                </div>
            </TableCell>
            <TableCell
                className={cn(dataTableCellClass(), 'hidden sm:table-cell')}
            >
                <Badge
                    variant="outline"
                    className="max-w-44 truncate border-border font-normal dark:border-white/10"
                >
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
            <TableCell
                className={cn(
                    dataTableCellClass(),
                    'hidden whitespace-nowrap md:table-cell',
                )}
            >
                {formatDisplayDate(doc.expiry_date)}
            </TableCell>
            <TableCell
                className={cn(dataTableCellClass(), 'hidden lg:table-cell')}
            >
                <span
                    className={cn(
                        'text-sm',
                        expiryRemainingClass(doc.expiry_status),
                    )}
                >
                    {doc.expiry_label}
                </span>
            </TableCell>
            <TableCell
                className={cn(dataTableCellClass(), 'hidden sm:table-cell')}
            >
                <DocumentExpiryBadge status={doc.expiry_status} />
            </TableCell>
            <TableCell
                className={cn(dataTableActionsCellClass(), 'min-w-[13.5rem]')}
            >
                <DocumentModuleRowActions
                    doc={doc}
                    viewHref={viewHref}
                    canDownload={canDownload}
                    canUpload={canUpload}
                    canDelete={canDelete}
                    onEdit={onEdit ? () => onEdit(doc) : undefined}
                    onReplace={onReplace ? () => onReplace(doc) : undefined}
                    onDelete={onDelete ? () => onDelete(doc) : undefined}
                />
            </TableCell>
        </TableRow>
    );
}
