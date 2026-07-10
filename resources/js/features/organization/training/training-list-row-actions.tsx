import { Pencil, RefreshCw, Trash2 } from 'lucide-react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

const iconButtonClass =
    'h-8 w-8 rounded-lg text-muted-foreground hover:bg-accent hover:text-foreground dark:text-zinc-400 dark:hover:bg-white/10 dark:hover:text-zinc-100';

type TrainingListRowActionsProps = {
    onEdit?: () => void;
    onReplace?: () => void;
    onDelete?: () => void;
    showReplace?: boolean;
    className?: string;
};

export function TrainingListRowActions({
    onEdit,
    onReplace,
    onDelete,
    showReplace = false,
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
            {onEdit ? (
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
            {onDelete ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="h-8 w-8 rounded-lg text-red-400/70 hover:bg-red-500/10 hover:text-red-400"
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
