import { Download, ExternalLink, Pencil, RefreshCw, Trash2 } from 'lucide-react';
import type { ReactElement } from 'react';
import { ListTableCrudActions } from '@/components/list-table-actions';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { documents } from '@/routes/organization';

const iconButtonClass =
    'h-8 w-8 rounded-lg text-muted-foreground hover:bg-accent hover:text-foreground dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-zinc-100';
const dangerIconButtonClass =
    'h-8 w-8 rounded-lg text-red-400/70 hover:bg-red-500/10 hover:text-red-400';

type DocumentListRowActionsProps = {
    documentId: number;
    fileUrl: string;
    viewHref: string;
    onReplace?: () => void;
    onEdit?: () => void;
    onDelete?: () => void;
    showDownload?: boolean;
    showReplace?: boolean;
    showEdit?: boolean;
    showDelete?: boolean;
    className?: string;
};

export function DocumentListRowActions({
    documentId,
    fileUrl,
    viewHref,
    onReplace,
    onEdit,
    onDelete,
    showDownload = false,
    showReplace = false,
    showEdit = false,
    showDelete = false,
    className,
}: DocumentListRowActionsProps): ReactElement {
    return (
        <div
            className={cn(
                'inline-flex shrink-0 flex-nowrap items-center justify-end gap-0.5',
                className,
            )}
            onClick={(event) => event.stopPropagation()}
            onKeyDown={(event) => event.stopPropagation()}
            role="presentation"
        >
            <ListTableCrudActions
                viewHref={viewHref}
                onEdit={showEdit && onEdit ? onEdit : undefined}
                onDelete={showDelete && onDelete ? onDelete : undefined}
                showEdit={showEdit}
                showDelete={showDelete}
            />
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
        </div>
    );
}

export function DocumentShowHeaderActions({
    documentId,
    fileUrl,
    onReplace,
    onEdit,
    onDelete,
    showDownload = false,
    showReplace = false,
    showEdit = false,
    showDelete = false,
}: Omit<DocumentListRowActionsProps, 'viewHref' | 'className'>): ReactElement {
    return (
        <div className="flex flex-wrap items-center gap-2">
            {showEdit && onEdit ? (
                <Button type="button" variant="outline" size="sm" className="rounded-xl" onClick={onEdit}>
                    <Pencil className="mr-2 h-4 w-4" />
                    Edit
                </Button>
            ) : null}
            {showReplace && onReplace ? (
                <Button type="button" variant="outline" size="sm" className="rounded-xl" onClick={onReplace}>
                    <RefreshCw className="mr-2 h-4 w-4" />
                    Replace
                </Button>
            ) : null}
            {showDownload ? (
                <Button asChild variant="outline" size="sm" className="rounded-xl">
                    <a href={documents.files.download.url({ document: documentId })}>
                        <Download className="mr-2 h-4 w-4" />
                        Download
                    </a>
                </Button>
            ) : null}
            <Button asChild variant="outline" size="sm" className="rounded-xl">
                <a href={fileUrl} target="_blank" rel="noreferrer">
                    <ExternalLink className="mr-2 h-4 w-4" />
                    Open file
                </a>
            </Button>
            {showDelete && onDelete ? (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="rounded-xl text-red-400/80 hover:bg-red-500/10 hover:text-red-400"
                    onClick={onDelete}
                >
                    <Trash2 className="mr-2 h-4 w-4" />
                    Delete
                </Button>
            ) : null}
        </div>
    );
}
