import { Download, Eye, ExternalLink, History, Pencil, RefreshCw, Trash2 } from 'lucide-react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { documents } from '@/routes/organization';

const iconButtonClass =
    'h-8 w-8 rounded-lg text-muted-foreground hover:bg-accent hover:text-foreground dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-zinc-100';
const dangerIconButtonClass =
    'h-8 w-8 rounded-lg text-red-400/70 hover:bg-red-500/10 hover:text-red-400';
const primaryIconButtonClass =
    'h-8 w-8 rounded-lg text-primary/80 hover:bg-primary/10 hover:text-primary';

type ManagementDocumentActionsProps = {
    documentId: number;
    canPreview: boolean;
    fileUrl: string;
    onPreview: () => void;
    onVersions?: () => void;
    onReplace?: () => void;
    onEdit?: () => void;
    onDelete?: () => void;
    showDownload?: boolean;
    showVersions?: boolean;
    showReplace?: boolean;
    showEdit?: boolean;
    showDelete?: boolean;
    className?: string;
};

export function ManagementDocumentActions({
    documentId,
    canPreview,
    fileUrl,
    onPreview,
    onVersions,
    onReplace,
    onEdit,
    onDelete,
    showDownload = false,
    showVersions = false,
    showReplace = false,
    showEdit = false,
    showDelete = false,
    className,
}: ManagementDocumentActionsProps): ReactElement {
    return (
        <div
            className={cn(
                'inline-flex shrink-0 flex-nowrap items-center justify-end gap-0.5',
                className,
            )}
            onClick={(event) => event.stopPropagation()}
            role="presentation"
        >
            {canPreview ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className={primaryIconButtonClass}
                    title="Preview"
                    aria-label="Preview"
                    onClick={onPreview}
                >
                    <Eye className="size-4" />
                </Button>
            ) : null}
            {showDownload ? (
                <Button
                    asChild
                    variant="ghost"
                    size="icon"
                    className={iconButtonClass}
                    title="Download"
                    aria-label="Download"
                >
                    <a href={documents.files.download.url({ document: documentId })}>
                        <Download className="size-4" />
                    </a>
                </Button>
            ) : null}
            <Button
                asChild
                variant="ghost"
                size="icon"
                className={iconButtonClass}
                title="View file"
                aria-label="View file"
            >
                <a href={fileUrl} target="_blank" rel="noreferrer">
                    <ExternalLink className="size-4" />
                </a>
            </Button>
            {showVersions && onVersions ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className={iconButtonClass}
                    title="Versions"
                    aria-label="Versions"
                    onClick={onVersions}
                >
                    <History className="size-4" />
                </Button>
            ) : null}
            {showReplace && onReplace ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className={iconButtonClass}
                    title="Replace file"
                    aria-label="Replace file"
                    onClick={onReplace}
                >
                    <RefreshCw className="size-4" />
                </Button>
            ) : null}
            {showEdit && onEdit ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className={iconButtonClass}
                    title="Edit"
                    aria-label="Edit"
                    onClick={onEdit}
                >
                    <Pencil className="size-4" />
                </Button>
            ) : null}
            {showDelete && onDelete ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className={dangerIconButtonClass}
                    title="Delete"
                    aria-label="Delete"
                    onClick={onDelete}
                >
                    <Trash2 className="size-4" />
                </Button>
            ) : null}
        </div>
    );
}
