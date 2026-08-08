import { router } from '@inertiajs/react';
import {
    Download,
    ExternalLink,
    MoreVertical,
    Pencil,
    RefreshCw,
    Trash2,
} from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { DocumentExpiryBadge } from '@/features/organization/documents/shared/document-expiry-badge';
import { DocumentFileIcon } from '@/features/organization/documents/shared/document-file-icon';
import type { DocumentBrowseItem } from '@/features/organization/documents/shared/types';
import { formatDisplayDate } from '@/lib/format-date';
import { cn, formatBytes } from '@/lib/utils';
import documentRoutes from '@/routes/organization/documents';

export function EmployeeDocumentMobileCard({
    doc,
    viewHref,
    canDownload = false,
    canUpload = false,
    canDelete = false,
    selected = false,
    onSelectedChange,
    selectionMode = false,
    onEdit,
    onReplace,
    onDelete,
}: {
    doc: DocumentBrowseItem;
    viewHref: string;
    canDownload?: boolean;
    canUpload?: boolean;
    canDelete?: boolean;
    selected?: boolean;
    onSelectedChange?: (selected: boolean) => void;
    selectionMode?: boolean;
    onEdit?: () => void;
    onReplace?: () => void;
    onDelete?: () => void;
}) {
    const downloadUrl = documentRoutes.files.download.url({
        document: doc.id,
    });

    return (
        <div
            className={cn(
                'group relative flex flex-col rounded-xl border bg-card transition-colors duration-150',
                'border-border/60 dark:border-white/8',
                selected
                    ? 'border-primary/30 bg-primary/5 ring-1 ring-primary/20'
                    : 'hover:border-border hover:bg-muted/20',
            )}
        >
            {/* Top row: checkbox + file icon + name + overflow menu */}
            <div
                className="flex min-w-0 items-start gap-3 p-3"
                onClick={(e) => {
                    if (
                        e.target instanceof Element &&
                        e.target.closest(
                            '[data-slot="checkbox"], [role="checkbox"], button, a, [data-row-ignore-click]',
                        )
                    ) {
                        return;
                    }

                    router.visit(viewHref);
                }}
                role="button"
                tabIndex={0}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        router.visit(viewHref);
                    }
                }}
                aria-label={`View ${doc.document_name}`}
            >
                {/* Checkbox */}
                {selectionMode ? (
                    <div
                        className="mt-0.5 shrink-0"
                        data-row-ignore-click
                        onClick={(e) => e.stopPropagation()}
                    >
                        <Checkbox
                            checked={selected}
                            onCheckedChange={(value) =>
                                onSelectedChange?.(value === true)
                            }
                            aria-label={`Select ${doc.document_name}`}
                        />
                    </div>
                ) : null}

                {/* File icon */}
                <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-white/[0.06] bg-muted/30">
                    <DocumentFileIcon
                        mimeType={doc.mime_type}
                        fileName={doc.document_name}
                        className="h-5 w-5"
                    />
                </div>

                {/* Name + type */}
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm leading-snug font-semibold text-foreground">
                        {doc.document_name}
                    </p>
                    <div className="mt-1 flex min-w-0 flex-wrap items-center gap-1.5">
                        <Badge
                            variant="outline"
                            className="max-w-[11rem] truncate border-border text-[10px] font-normal dark:border-white/10"
                        >
                            {doc.document_type}
                        </Badge>
                        {doc.size_bytes ? (
                            <span className="shrink-0 text-[10px] text-muted-foreground tabular-nums">
                                {formatBytes(doc.size_bytes)}
                            </span>
                        ) : null}
                    </div>
                </div>

                {/* Overflow menu */}
                <div data-row-ignore-click onClick={(e) => e.stopPropagation()}>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="h-8 w-8 shrink-0 rounded-lg text-muted-foreground hover:bg-muted/60 hover:text-foreground"
                                aria-label="More actions"
                            >
                                <MoreVertical className="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-44">
                            <DropdownMenuItem asChild>
                                <a
                                    href={doc.file_url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="flex items-center gap-2"
                                >
                                    <ExternalLink className="h-3.5 w-3.5" />
                                    Open file
                                </a>
                            </DropdownMenuItem>
                            {canDownload ? (
                                <DropdownMenuItem asChild>
                                    <a
                                        href={downloadUrl}
                                        className="flex items-center gap-2"
                                    >
                                        <Download className="h-3.5 w-3.5" />
                                        Download
                                    </a>
                                </DropdownMenuItem>
                            ) : null}
                            {canUpload && onEdit ? (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        onClick={onEdit}
                                        className="flex items-center gap-2"
                                    >
                                        <Pencil className="h-3.5 w-3.5" />
                                        Edit details
                                    </DropdownMenuItem>
                                </>
                            ) : null}
                            {canUpload && onReplace ? (
                                <DropdownMenuItem
                                    onClick={onReplace}
                                    className="flex items-center gap-2"
                                >
                                    <RefreshCw className="h-3.5 w-3.5" />
                                    Replace file
                                </DropdownMenuItem>
                            ) : null}
                            {canDelete && onDelete ? (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        onClick={onDelete}
                                        className="flex items-center gap-2 text-red-400 focus:text-red-400"
                                    >
                                        <Trash2 className="h-3.5 w-3.5" />
                                        Delete
                                    </DropdownMenuItem>
                                </>
                            ) : null}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            {/* Metadata row */}
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-border/40 px-3 py-2 dark:border-white/5">
                <DocumentExpiryBadge
                    status={doc.expiry_status}
                    className="text-[10px]"
                />
                {doc.expiry_date ? (
                    <span className="text-[11px] text-muted-foreground">
                        Exp:{' '}
                        <span className="font-medium text-foreground/80">
                            {formatDisplayDate(doc.expiry_date)}
                        </span>
                    </span>
                ) : null}
                {doc.document_number?.trim() ? (
                    <span className="truncate font-mono text-[11px] text-muted-foreground">
                        #{doc.document_number}
                    </span>
                ) : null}
            </div>

            {/* Action bar */}
            <div className="flex items-center gap-1.5 border-t border-border/40 px-2.5 py-1.5 dark:border-white/5">
                <Button
                    asChild
                    variant="ghost"
                    size="sm"
                    className="h-8 flex-1 rounded-lg text-xs text-muted-foreground hover:bg-muted/60 hover:text-foreground"
                >
                    <a href={viewHref} onClick={(e) => e.stopPropagation()}>
                        View details
                    </a>
                </Button>
                {canDownload ? (
                    <Button
                        asChild
                        variant="ghost"
                        size="sm"
                        className="h-8 flex-1 rounded-lg text-xs text-muted-foreground hover:bg-muted/60 hover:text-foreground"
                    >
                        <a href={downloadUrl}>
                            <Download className="mr-1.5 h-3.5 w-3.5" />
                            Download
                        </a>
                    </Button>
                ) : null}
                <Button
                    asChild
                    variant="ghost"
                    size="sm"
                    className="h-8 flex-1 rounded-lg text-xs text-muted-foreground hover:bg-muted/60 hover:text-foreground"
                >
                    <a
                        href={doc.file_url}
                        target="_blank"
                        rel="noreferrer"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <ExternalLink className="mr-1.5 h-3.5 w-3.5" />
                        Open
                    </a>
                </Button>
            </div>
        </div>
    );
}
