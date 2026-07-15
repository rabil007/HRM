import { ListTableCrudActions } from '@/components/list-table-actions';
import { cn } from '@/lib/utils';

type SeaServiceListRowActionsProps = {
    viewHref?: string;
    onEdit?: () => void;
    onDelete?: () => void;
    showEdit?: boolean;
    showDelete?: boolean;
    className?: string;
};

export function SeaServiceListRowActions({
    viewHref,
    onEdit,
    onDelete,
    showEdit = false,
    showDelete = false,
    className,
}: SeaServiceListRowActionsProps) {
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
        </div>
    );
}
