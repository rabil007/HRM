import { router } from '@inertiajs/react';
import {
    dataTableActionsCellClass,
    dataTableBodyRowClass,
    dataTableCellClass,
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
import type { WhatsAppTemplateOption } from '@/features/organization/documents/whatsapp-template/types';
import { formatDisplayDate } from '@/lib/format-date';
import type { PhoneCountryOption } from '@/lib/phone-with-dial-code';
import { cn, formatBytes } from '@/lib/utils';

function formatOptionalDate(value: string | null): string {
    return value ? formatDisplayDate(value) : '—';
}

export function EmployeeDocumentTableRow({
    doc,
    employeeId,
    employeeName,
    employeePhone,
    viewHref,
    canDownload = false,
    canUpload = false,
    canDelete = false,
    onEdit,
    onReplace,
    onDelete,
    canSendWhatsAppTemplate = false,
    whatsappTemplates = [],
    countries,
    selected = false,
    onSelectedChange,
    selectionMode = false,
}: {
    doc: DocumentBrowseItem;
    employeeId: number;
    employeeName: string;
    employeePhone?: string | null;
    viewHref: string;
    canDownload?: boolean;
    canUpload?: boolean;
    canDelete?: boolean;
    onEdit?: (doc: DocumentBrowseItem) => void;
    onReplace?: (doc: DocumentBrowseItem) => void;
    onDelete?: (doc: DocumentBrowseItem) => void;
    canSendWhatsAppTemplate?: boolean;
    whatsappTemplates?: WhatsAppTemplateOption[];
    countries: PhoneCountryOption[];
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
            onClick={() => router.visit(viewHref)}
        >
            {selectionMode ? (
                <TableCell
                    className="w-10 px-3 py-4 align-middle"
                    onClick={(event) => event.stopPropagation()}
                >
                    <Checkbox
                        checked={selected}
                        onCheckedChange={(value) =>
                            onSelectedChange?.(value === true)
                        }
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
                        <p className="truncate text-sm font-semibold text-foreground">
                            {doc.document_name}
                        </p>
                        <p className="mt-0.5 truncate text-xs text-muted-foreground sm:hidden">
                            {doc.document_type}
                        </p>
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
                className={cn(dataTableCellClass(), 'hidden sm:table-cell')}
            >
                <Badge
                    variant="outline"
                    className="max-w-48 truncate border-border font-normal dark:border-white/10"
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
                    'hidden whitespace-nowrap tabular-nums md:table-cell',
                )}
            >
                {formatBytes(doc.size_bytes)}
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
                    canSendWhatsAppTemplate={canSendWhatsAppTemplate}
                    whatsappTemplates={whatsappTemplates}
                    countries={countries}
                    employeeId={employeeId}
                    employeeName={employeeName}
                    employeePhone={employeePhone}
                />
            </TableCell>
        </TableRow>
    );
}
