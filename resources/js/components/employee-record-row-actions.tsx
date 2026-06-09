import { Pencil, Trash2 } from 'lucide-react';
import type { ReactElement } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type EmployeeRecordRowActionsProps = {
    onEdit: () => void;
    onDelete: () => void;
    className?: string;
};

export function EmployeeRecordRowActions({
    onEdit,
    onDelete,
    className,
}: EmployeeRecordRowActionsProps): ReactElement {
    return (
        <div
            className={cn(
                'inline-flex shrink-0 flex-nowrap items-center justify-end gap-0.5',
                className,
            )}
            onClick={(event) => event.stopPropagation()}
            role="presentation"
        >
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="h-8 w-8 rounded-lg text-muted-foreground hover:bg-accent hover:text-accent-foreground dark:hover:bg-white/10 dark:hover:text-zinc-100"
                title="Edit"
                aria-label="Edit"
                onClick={onEdit}
            >
                <Pencil className="size-4" />
            </Button>
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
        </div>
    );
}
