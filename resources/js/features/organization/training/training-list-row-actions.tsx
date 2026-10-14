import { ExternalLink, Link2, Pencil, RefreshCw, Trash2 } from 'lucide-react';
import type { ReactElement } from 'react';
import { ListTableCrudActions } from '@/components/list-table-actions';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

const iconButtonClass =
    'h-8 w-8 rounded-lg text-muted-foreground hover:bg-accent hover:text-foreground dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-zinc-100';

type TrainingListRowActionsProps = {
    viewHref?: string;
    certificateUrl?: string | null;
    onEdit?: () => void;
    onReplace?: () => void;
    onLink?: () => void;
    onDelete?: () => void;
    showEdit?: boolean;
    showReplace?: boolean;
    showLink?: boolean;
    showDelete?: boolean;
    className?: string;
};

export function TrainingListRowActions({
    viewHref,
    certificateUrl,
    onEdit,
    onReplace,
    onLink,
    onDelete,
    showEdit = false,
    showReplace = false,
    showLink = false,
    showDelete = false,
    className,
}: TrainingListRowActionsProps): ReactElement {
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
            {certificateUrl ? (
                <Button
                    asChild
                    variant="ghost"
                    size="icon"
                    className={iconButtonClass}
                    title="View file"
                    aria-label="View file"
                >
                    <a href={certificateUrl} target="_blank" rel="noreferrer">
                        <ExternalLink className="size-4" />
                    </a>
                </Button>
            ) : null}
            {showLink && onLink ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className={iconButtonClass}
                    title="Link existing document"
                    aria-label="Link existing document"
                    onClick={onLink}
                >
                    <Link2 className="size-4" />
                </Button>
            ) : null}
            {showReplace && onReplace ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className={iconButtonClass}
                    title="Replace certificate"
                    aria-label="Replace certificate"
                    onClick={onReplace}
                >
                    <RefreshCw className="size-4" />
                </Button>
            ) : null}
        </div>
    );
}

type TrainingShowHeaderActionsProps = {
    certificateUrl?: string | null;
    onReplace?: () => void;
    onEdit?: () => void;
    onDelete?: () => void;
    showReplace?: boolean;
};

export function TrainingShowHeaderActions({
    certificateUrl,
    onReplace,
    onEdit,
    onDelete,
    showReplace = false,
}: TrainingShowHeaderActionsProps): ReactElement {
    return (
        <div className="flex flex-wrap items-center gap-2">
            {onEdit ? (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="rounded-xl"
                    onClick={onEdit}
                >
                    <Pencil className="mr-2 h-4 w-4" />
                    Edit
                </Button>
            ) : null}
            {showReplace && onReplace ? (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="rounded-xl"
                    onClick={onReplace}
                >
                    <RefreshCw className="mr-2 h-4 w-4" />
                    Replace
                </Button>
            ) : null}
            {certificateUrl ? (
                <Button
                    asChild
                    variant="outline"
                    size="sm"
                    className="rounded-xl"
                >
                    <a href={certificateUrl} target="_blank" rel="noreferrer">
                        <ExternalLink className="mr-2 h-4 w-4" />
                        Open certificate
                    </a>
                </Button>
            ) : null}
            {onDelete ? (
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
