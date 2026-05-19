import { Eye, Pencil, Trash2 } from 'lucide-react';
import type { MouseEvent } from 'react';
import { TableRowActions } from '@/components/table-row-actions';
import type { TableRowActionItem } from '@/components/table-row-actions';

type ListTableCrudActionsProps = {
    onView?: (event: MouseEvent<HTMLButtonElement>) => void;
    viewHref?: string;
    onEdit?: (event: MouseEvent<HTMLButtonElement>) => void;
    onDelete?: (event: MouseEvent<HTMLButtonElement>) => void;
    showView?: boolean;
    showEdit?: boolean;
    showDelete?: boolean;
};

/** Standard View / Edit / Delete row actions (ghost icon buttons — matches employee record tabs). */
export function ListTableCrudActions({
    onView,
    viewHref,
    onEdit,
    onDelete,
    showView = true,
    showEdit = true,
    showDelete = true,
}: ListTableCrudActionsProps) {
    const actions: TableRowActionItem[] = [
        {
            label: 'View',
            icon: Eye,
            href: viewHref,
            onClick: onView,
            hidden: !showView || (!viewHref && !onView),
        },
        {
            label: 'Edit',
            icon: Pencil,
            onClick: onEdit,
            hidden: !showEdit || !onEdit,
        },
        {
            label: 'Delete',
            icon: Trash2,
            variant: 'danger',
            onClick: onDelete,
            hidden: !showDelete || !onDelete,
        },
    ];

    return <TableRowActions actions={actions} />;
}
