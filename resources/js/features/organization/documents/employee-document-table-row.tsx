import { router } from '@inertiajs/react';
import {
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
    recordsTableTdClass,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { TableCell, TableRow } from '@/components/ui/table';
import { DocumentModuleRowActions } from '@/features/organization/documents/shared/document-actions/document-module-row-actions';
import { DocumentExpiryBadge } from '@/features/organization/documents/shared/document-expiry-badge';
import {
    DocumentExpiryDisplay,
    DocumentExpiryStatusCell,
} from '@/features/organization/documents/shared/document-expiry-display';
import { DocumentFileIcon } from '@/features/organization/documents/shared/document-file-icon';
import { DocumentUploadedDisplay } from '@/features/organization/documents/shared/document-uploaded-display';
import type { DocumentBrowseItem } from '@/features/organization/documents/shared/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn, formatBytes } from '@/lib/utils';

function formatOptionalDate(value: string | null): string {
    return value ? formatDisplayDate(value) : '—';
}

export function EmployeeDocumentTableRow({
    doc,
    viewHref,
    canDownload = false,
    canUpload = false,
    canDelete = false,
    onEdit,
    onReplace,
    onDelete,
    selected = false,
    onSelectedChange,
    selectionMode = false,
}: {
    doc: DocumentBrowseItem;
    viewHref: string;
    canDownload?: boolean;
    canUpload?: boolean;
    canDelete?: boolean;
    onEdit?: (doc: DocumentBrowseItem) => void;
    onReplace?: (doc: DocumentBrowseItem) => void;
    onDelete?: (doc: DocumentBrowseItem) => void;
    selected?: boolean;
    onSelectedChange?: (selected: boolean) => void;
    selectionMode?: boolean;
}) {
    return (
        <TableRow
            className={cn(
                dataTableBodyRowClass(false),
                'cursor-pointer',
                selected && 'bg-primary/5',
            )}
            onClick={(event) => {
                const target = event.target;

                if (
                    !(target instanceof Element) ||
                    target.closest(
                        '[data-slot="checkbox"], [role="checkbox"], button, a, [data-row-ignore-click]',
                    )
                ) {
                    return;
                }

                router.visit(viewHref);
            }}
        >
            {selectionMode ? (
                <td
                    className={cn(
                        recordsTableTdClass(),
                        'w-10 px-3 first:pl-3',
                    )}
                    data-row-ignore-click
                    onClick={(event) => event.stopPropagation()}
                    onPointerDown={(event) => event.stopPropagation()}
                >
                    <Checkbox
                        checked={selected}
                        onCheckedChange={(value) =>
                            onSelectedChange?.(value === true)
                        }
                        aria-label={`Select ${doc.document_name}`}
                    />
                </td>
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
                        <p className="truncate text-sm font-semibold text-foreground">
                            {doc.document_name}
                        </p>
                        <div className="mt-1 flex min-w-0 items-center gap-2">
                            <Badge
                                variant="outline"
                                className="max-w-full truncate border-border font-normal dark:border-white/10"
                            >
                                {doc.document_type}
                            </Badge>
                            <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                {formatBytes(doc.size_bytes)}
                            </span>
                        </div>
                        <p className="mt-1 md:hidden">
                            <DocumentExpiryBadge
                                status={doc.expiry_status}
                                className="text-[10px]"
                            />
                        </p>
                    </div>
                </div>
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
                {formatOptionalDate(doc.issue_date)}
            </TableCell>
            <TableCell
                className={cn(
                    dataTableCellClass(),
                    'hidden whitespace-nowrap lg:table-cell',
                )}
            >
                <DocumentExpiryDisplay doc={doc} />
            </TableCell>
            <TableCell
                className={cn(
                    dataTableCellClass(),
                    'hidden whitespace-nowrap lg:table-cell',
                )}
            >
                <DocumentExpiryStatusCell status={doc.expiry_status} />
            </TableCell>
            <TableCell
                className={cn(
                    dataTableCellClass(),
                    'hidden min-w-[120px] xl:table-cell',
                )}
            >
                <DocumentUploadedDisplay doc={doc} />
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
